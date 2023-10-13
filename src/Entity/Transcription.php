<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass="App\Repository\TranscriptionRepository")
 * @ORM\Table(name="annotation")
 */
class Transcription
{
    /**
     * @ORM\Id()
     * @ORM\GeneratedValue()
     * @ORM\Column(type="integer")
     */
    private $id;

    /**
     * @ORM\Column(type="string", length=255)
     */
    private $transcriptionId;

    /**
     * @ORM\Column(type="text")
     */
    private $data;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTranscriptionId(): ?string
    {
        return $this->transcriptionId;
    }

    public function setTranscriptionId(string $transcriptionId): self
    {
        $this->transcriptionId = $transcriptionId;

        return $this;
    }

    public function getData(): ?string
    {
        return $this->data;
    }

    public function setData(string $data): self
    {
        $this->data = $data;

        return $this;
    }
}
