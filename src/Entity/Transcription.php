<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: "App\Repository\TranscriptionRepository")]
#[ORM\Table(name: "transcription")]
class Transcription
{
    #[ORM\Id()]
    #[ORM\GeneratedValue()]
    #[ORM\Column(type: "integer")]
    private int $id;

    #[ORM\Column(type: "string", length: 255)]
    private ?string $transcriptionId;

    #[ORM\Column(type: "string", length: 255)]
    private ?string $altoUrl;

    #[ORM\Column(type: "text")]
    private ?string $data;

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

    public function getAltoUrl(): ?string
    {
        return $this->altoUrl;
    }

    public function setAltoUrl($altoUrl): self
    {
        $this->altoUrl = $altoUrl;
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
