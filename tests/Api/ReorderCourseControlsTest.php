<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\CourseControl;
use App\Entity\Organization;
use App\Tests\Factory\ControlFactory;
use App\Tests\Factory\CourseControlFactory;
use App\Tests\Factory\CourseFactory;
use App\Tests\Factory\EventFactory;
use App\Tests\Factory\OrganizationFactory;
use App\Tests\Factory\UserFactory;
use Symfony\Component\HttpFoundation\Response;

/**
 * Locks in the reorder endpoint behaviour: one POST swaps positions in a
 * single transaction without tripping the (course_id, position) unique
 * constraint and without parameter-binding bugs (DBAL 4 dropped the
 * `Statement::executeStatement($params)` shorthand we used to rely on).
 */
final class ReorderCourseControlsTest extends ApiResourceTestCase
{
    public function testReorderSwapsPositionsAtomically(): void
    {
        // Setup: a course with 3 controls in positions 1, 2, 3.
        $owner = UserFactory::createOne();
        $organization = OrganizationFactory::new()->withMember($owner)->create();
        $event = EventFactory::createOne(['organization' => $organization]);
        $course = CourseFactory::createOne(['event' => $event]);

        $ctrls = [];
        $ccs = [];
        for ($i = 0; $i < 3; ++$i) {
            $ctrls[$i] = ControlFactory::createOne(['event' => $event]);
            $ccs[$i] = CourseControlFactory::createOne([
                'course' => $course,
                'control' => $ctrls[$i],
                'position' => $i + 1,
            ]);
        }

        $client = $this->createAuthenticatedClient($owner);
        $client->request(
            'POST',
            sprintf('/api/courses/%s/course_controls/reorder', $course->getId()->toRfc4122()),
            [
                'json' => [
                    // New order: 3 first, then 1, then 2.
                    'ids' => [
                        $this->iriFor($ccs[2]),
                        $this->iriFor($ccs[0]),
                        $this->iriFor($ccs[1]),
                    ],
                ],
            ],
        );

        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        // Verify positions actually rewrote 1..3 in the requested order.
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->clear();
        $fresh0 = $em->find(CourseControl::class, $ccs[0]->getId());
        $fresh1 = $em->find(CourseControl::class, $ccs[1]->getId());
        $fresh2 = $em->find(CourseControl::class, $ccs[2]->getId());
        self::assertSame(2, $fresh0->getPosition(), 'ccs[0] should be second now.');
        self::assertSame(3, $fresh1->getPosition(), 'ccs[1] should be third now.');
        self::assertSame(1, $fresh2->getPosition(), 'ccs[2] should be first now.');
    }

    public function testReorderRejectsPartialList(): void
    {
        // The endpoint requires the full ordered list — a partial list
        // would leave gaps in the (course, position) sequence.
        $owner = UserFactory::createOne();
        $organization = OrganizationFactory::new()->withMember($owner)->create();
        $event = EventFactory::createOne(['organization' => $organization]);
        $course = CourseFactory::createOne(['event' => $event]);

        $ccs = [];
        for ($i = 0; $i < 3; ++$i) {
            $ctrl = ControlFactory::createOne(['event' => $event]);
            $ccs[$i] = CourseControlFactory::createOne([
                'course' => $course,
                'control' => $ctrl,
                'position' => $i + 1,
            ]);
        }

        $client = $this->createAuthenticatedClient($owner);
        $client->request(
            'POST',
            sprintf('/api/courses/%s/course_controls/reorder', $course->getId()->toRfc4122()),
            ['json' => ['ids' => [$this->iriFor($ccs[0])]]], // only 1 of 3
        );

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    public function testReorderForbidsNonManager(): void
    {
        // A user who's not a member of the owning organization can't reorder.
        $owner = UserFactory::createOne();
        $stranger = UserFactory::createOne();
        $organization = OrganizationFactory::new()->withMember($owner)->create();
        $event = EventFactory::createOne(['organization' => $organization]);
        $course = CourseFactory::createOne(['event' => $event]);
        $ctrl = ControlFactory::createOne(['event' => $event]);
        $cc = CourseControlFactory::createOne([
            'course' => $course,
            'control' => $ctrl,
            'position' => 1,
        ]);

        $client = $this->createAuthenticatedClient($stranger);
        $client->request(
            'POST',
            sprintf('/api/courses/%s/course_controls/reorder', $course->getId()->toRfc4122()),
            ['json' => ['ids' => [$this->iriFor($cc)]]],
        );

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    private function iriFor(CourseControl $cc): string
    {
        return '/api/course_controls/'.$cc->getId()->toRfc4122();
    }
}
