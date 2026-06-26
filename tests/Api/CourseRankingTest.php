<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\Course;
use App\Enum\ActivityStatus;
use App\Enum\CourseType;
use App\Tests\Factory\ActivityFactory;
use App\Tests\Factory\CourseFactory;
use App\Tests\Factory\UserFactory;
use Symfony\Component\HttpFoundation\Response;

final class CourseRankingTest extends ApiResourceTestCase
{
    public function testRankingByDurationForClassicCourse(): void
    {
        $course = CourseFactory::createOne(['type' => CourseType::Classic]);
        $start = new \DateTimeImmutable('2026-05-01 09:00:00');

        $fast = UserFactory::createOne(['firstName' => 'Fast', 'lastName' => 'Runner']);
        $mid = UserFactory::createOne(['firstName' => 'Mid', 'lastName' => 'Runner']);
        $slow = UserFactory::createOne(['firstName' => 'Slow', 'lastName' => 'Runner']);

        ActivityFactory::createOne([
            'course' => $course, 'user' => $slow,
            'status' => ActivityStatus::Completed,
            'startedAt' => $start, 'finishedAt' => $start->modify('+90 minutes'),
            'totalDurationSec' => 5400,
        ]);
        ActivityFactory::createOne([
            'course' => $course, 'user' => $fast,
            'status' => ActivityStatus::Completed,
            'startedAt' => $start, 'finishedAt' => $start->modify('+30 minutes'),
            'totalDurationSec' => 1800,
        ]);
        ActivityFactory::createOne([
            'course' => $course, 'user' => $mid,
            'status' => ActivityStatus::Completed,
            'startedAt' => $start, 'finishedAt' => $start->modify('+60 minutes'),
            'totalDurationSec' => 3600,
        ]);
        // Abandoned activity should NOT show up in the ranking.
        ActivityFactory::createOne([
            'course' => $course, 'user' => UserFactory::createOne(),
            'status' => ActivityStatus::Abandoned,
            'startedAt' => $start, 'finishedAt' => null,
            'totalDurationSec' => null,
        ]);

        $client = $this->createAuthenticatedClient();
        $response = $client->request('GET', $this->rankingUrl($course));

        self::assertResponseIsSuccessful();
        $data = $response->toArray();
        self::assertSame(3, $data['total']);
        self::assertSame(['Fast', 'Mid', 'Slow'], array_map(
            fn (array $row): string => $row['participant']['firstName'],
            $data['items'],
        ));
        self::assertSame([1800, 3600, 5400], array_map(
            fn (array $row): int => $row['durationSeconds'],
            $data['items'],
        ));
    }

    public function testRankingByScoreForScoreCourse(): void
    {
        $course = CourseFactory::createOne(['type' => CourseType::Score]);
        $start = new \DateTimeImmutable('2026-05-01 09:00:00');

        $hunter = UserFactory::createOne(['firstName' => 'Hunter']);
        $sprinter = UserFactory::createOne(['firstName' => 'Sprinter']);

        // Sprinter finished faster but with fewer points: rank 2.
        ActivityFactory::createOne([
            'course' => $course, 'user' => $sprinter,
            'status' => ActivityStatus::Completed,
            'startedAt' => $start, 'finishedAt' => $start->modify('+30 minutes'),
            'totalDurationSec' => 1800, 'totalScore' => 60,
        ]);
        ActivityFactory::createOne([
            'course' => $course, 'user' => $hunter,
            'status' => ActivityStatus::Completed,
            'startedAt' => $start, 'finishedAt' => $start->modify('+58 minutes'),
            'totalDurationSec' => 3480, 'totalScore' => 120,
        ]);

        $client = $this->createAuthenticatedClient();
        $data = $client->request('GET', $this->rankingUrl($course))->toArray();

        self::assertSame(['Hunter', 'Sprinter'], array_map(
            fn (array $row): string => $row['participant']['firstName'],
            $data['items'],
        ));
    }

    public function testRankingCsv(): void
    {
        $course = CourseFactory::createOne(['type' => CourseType::Classic]);
        $start = new \DateTimeImmutable('2026-05-01 09:00:00');

        ActivityFactory::createOne([
            'course' => $course,
            'user' => UserFactory::createOne(['firstName' => 'Jane', 'lastName' => 'Doe']),
            'status' => ActivityStatus::Completed,
            'startedAt' => $start, 'finishedAt' => $start->modify('+45 minutes'),
            'totalDurationSec' => 2700,
        ]);

        $client = $this->createAuthenticatedClient();
        $response = $client->request('GET', $this->rankingUrl($course).'.csv');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'text/csv; charset=utf-8');
        $body = $response->getContent();
        self::assertStringContainsString('rank,firstName,lastName,durationSeconds', $body);
        self::assertStringContainsString('1,Jane,Doe,2700', $body);
    }

    public function testRankingRequiresAuthentication(): void
    {
        $course = CourseFactory::createOne();
        static::createClient()->request('GET', $this->rankingUrl($course));

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    private function rankingUrl(Course $course): string
    {
        return '/api/courses/'.$course->getId()->toRfc4122().'/ranking';
    }
}
