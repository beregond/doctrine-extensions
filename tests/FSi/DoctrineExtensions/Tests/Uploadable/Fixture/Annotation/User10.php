<?php

namespace FSi\DoctrineExtensions\Tests\Uploadable\Fixture\Annotation;

use Doctrine\ORM\Mapping as ORM;
use FSi\DoctrineExtensions\Uploadable\Mapping\Annotation\Uploadable;

/**
 * @ORM\Table()
 * @ORM\Entity()
 */
class User10
{
    /**
     * @var integer
     *
     * @ORM\Column(type="integer")
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    public $id;

    /**
     * @var string
     *
     * @ORM\Column(length=255, nullable=true)
     * @Uploadable(targetField="file", keymaker="wrong")
     */
    protected $fileKey;

    /**
     * @var mixed
     */
    protected $file;
}
