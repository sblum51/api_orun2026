<?php

declare(strict_types=1);

namespace App\Entity\Trait;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

trait TimestampableTrait
{
    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    // Every per-entity `<name>:read` group listed here so createdAt /
    // updatedAt land in the serialized output for whichever entity is
    // being read. Missing an entity here = the client sees Invalid Date
    // (`t.createdAt` undefined → `new Date(undefined)` fails).
    #[Groups([
        'tag:read',
        'event:read',
        'map:read',
        'team:read',
        'user:read',
        'course_control:read',
        'course-control:read',
        'control:read',
        'activity:read',
        'course:read',
        'organization:read',
        'organization_member:read',
    ])]
    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    #[Groups([
        'tag:read',
        'event:read',
        'map:read',
        'team:read',
        'user:read',
        'course_control:read',
        'course-control:read',
        'control:read',
        'activity:read',
        'course:read',
        'organization:read',
        'organization_member:read',
    ])]
    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    #[ORM\PrePersist]
    public function setCreatedAtCallback(): void
    {
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    #[ORM\PreUpdate]
    public function setUpdatedAtCallback(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
