<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: "App\Repository\ResourceDataRepository")]
#[ORM\Table(name: "resource_data")]
class ResourceData
{
    #[ORM\Id()]
    #[ORM\Column(type: "integer")]
    private $id;

    #[ORM\Id()]
    #[ORM\Column(type: "string", length: 50)]
    private $name;

    #[ORM\Column(type: "text")]
    private $value;

    public function getId(): ?string
    {
        return $this->id;
    }

    public function setId(string $id): self
    {
        $this->id = $id;

        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getValue(): ?string
    {
        return $this->value;
    }

    public function setValue(string $value): self
    {
        $this->value = $value;

        return $this;
    }
}
