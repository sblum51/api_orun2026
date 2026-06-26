<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Course;
use App\Repository\ActivityRepository;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Custom endpoints for course rankings — JSON for the UIs, CSV for download.
 */
final class CourseRankingController
{
    public function __construct(
        private readonly ActivityRepository $activityRepository,
    ) {
    }

    /**
     * Prefix cells that start with =, +, -, @, tab or CR with a single quote
     * so Excel / Numbers / LibreOffice treat them as plain text instead of
     * formulas. Runner-supplied names like "=cmd|'/c calc'!A1" would
     * otherwise execute the moment a manager opens the ranking CSV.
     */
    private function csvSafe(?string $v): string
    {
        if (null === $v || '' === $v) {
            return '';
        }

        return preg_match('/^[=+\-@\t\r]/', $v) ? "'".$v : $v;
    }

    #[Route(
        path: '/api/courses/{id}/ranking',
        name: 'api_course_ranking',
        requirements: ['id' => '[0-9a-f-]{36}'],
        methods: ['GET'],
    )]
    #[IsGranted('view', subject: 'course')]
    public function ranking(#[MapEntity(id: 'id')] Course $course): JsonResponse
    {
        $items = [];
        foreach ($this->activityRepository->findRanking($course) as $rank => $activity) {
            $participant = $activity->getUser();
            $items[] = [
                'rank' => $rank + 1,
                'activity' => '/api/activities/'.$activity->getId()->toRfc4122(),
                'participant' => [
                    'firstName' => $participant->getFirstName(),
                    'lastName' => $participant->getLastName(),
                ],
                'durationSeconds' => $activity->getTotalDurationSec(),
                'score' => $activity->getTotalScore(),
                'finishedAt' => $activity->getFinishedAt()?->format(\DateTimeInterface::ATOM),
            ];
        }

        return new JsonResponse([
            'course' => '/api/courses/'.$course->getId()->toRfc4122(),
            'courseType' => $course->getType()->value,
            'total' => \count($items),
            'items' => $items,
        ]);
    }

    #[Route(
        path: '/api/courses/{id}/ranking.csv',
        name: 'api_course_ranking_csv',
        requirements: ['id' => '[0-9a-f-]{36}'],
        methods: ['GET'],
    )]
    #[IsGranted('view', subject: 'course')]
    public function rankingCsv(#[MapEntity(id: 'id')] Course $course): Response
    {
        $activities = $this->activityRepository->findRanking($course);

        $handle = fopen('php://temp', 'r+b');
        if (false === $handle) {
            throw new \RuntimeException('Unable to open temp stream for CSV.');
        }
        fputcsv($handle, ['rank', 'firstName', 'lastName', 'durationSeconds', 'score', 'finishedAt'], escape: '\\');
        foreach ($activities as $rank => $activity) {
            $participant = $activity->getUser();
            fputcsv($handle, [
                $rank + 1,
                $this->csvSafe($participant->getFirstName()),
                $this->csvSafe($participant->getLastName()),
                $activity->getTotalDurationSec() ?? '',
                $activity->getTotalScore() ?? '',
                $activity->getFinishedAt()?->format(\DateTimeInterface::ATOM) ?? '',
            ], escape: '\\');
        }
        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        $response = new Response($csv, Response::HTTP_OK);
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', sprintf(
            'attachment; filename="ranking-%s.csv"',
            $course->getId()->toRfc4122(),
        ));

        return $response;
    }
}
