<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\IdentifiableTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Repository\MapRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: MapRepository::class)]
#[ORM\Table(name: 'maps')]
#[ORM\HasLifecycleCallbacks]
class Map
{
    use IdentifiableTrait;
    use TimestampableTrait;

    #[ORM\Column(type: 'string', length: 100)]
    #[Assert\NotBlank]
    private string $name;

    #[ORM\Column(type: 'string', length: 1000)]
    #[Assert\Url]
    private string $imageUrl;

    /**
     * Optional georeferencing bounds: { "north": lat, "south": lat, "east": lng, "west": lng }.
     * Lets the mobile app overlay the map on a base layer (OSM, etc.).
     *
     * @var array<string, float>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $bounds = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    #[Assert\Positive]
    private ?int $scale = null;

    #[ORM\ManyToOne(targetEntity: Course::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Course $course;

    public function __construct(Course $course, string $name, string $imageUrl)
    {
        $this->initializeUuid();
        $this->course = $course;
        $this->name = $name;
        $this->imageUrl = $imageUrl;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getImageUrl(): string
    {
        return $this->imageUrl;
    }

    public function setImageUrl(string $imageUrl): void
    {
        $this->imageUrl = $imageUrl;
    }

    /**
     * @return array<string, float>|null
     */
    public function getBounds(): ?array
    {
        return $this->bounds;
    }

    /**
     * @param array<string, float>|null $bounds
     */
    public function setBounds(?array $bounds): void
    {
        $this->bounds = $bounds;
    }

    public function getScale(): ?int
    {
        return $this->scale;
    }

    public function setScale(?int $scale): void
    {
        $this->scale = $scale;
    }

    public function getCourse(): Course
    {
        return $this->course;
    }
}
