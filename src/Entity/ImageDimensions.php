<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: "App\Repository\ImageDimensionsRepository")]
#[ORM\Table(name: "image_dimensions")]
class ImageDimensions
{
    #[ORM\Id()]
    #[ORM\Column(type: "integer")]
    private int $id;

    #[ORM\Column(type: "text", length: 32)]
    private string $checksum;

    #[ORM\Column(type: "integer")]
    private int $width;

    #[ORM\Column(type: "integer")]
    private int $height;

    public function getId(): ?string
    {
        return $this->id;
    }

    public function setId(string $id): self
    {
        $this->id = $id;
        return $this;
    }

    public function getChecksum(): string
    {
        return $this->checksum;
    }

    public function setChecksum(string $checksum): self
    {
        $this->checksum = $checksum;
        return $this;
    }

    public function getWidth(): int
    {
        return $this->width;
    }

    public function setWidth(int $width): self
    {
        $this->width = $width;
        return $this;
    }

    public function getHeight(): int
    {
        return $this->height;
    }

    public function setHeight(int $height): self
    {
        $this->height = $height;
        return $this;
    }
}
