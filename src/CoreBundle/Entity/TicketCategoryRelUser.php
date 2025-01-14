<?php

declare(strict_types=1);

/* For licensing terms, see /license.txt */

namespace Chamilo\CoreBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * CategoryRelUser.
 */
#[ORM\Table(name: 'ticket_category_rel_user')]
#[ORM\Entity]
class TicketCategoryRelUser
{
    #[ORM\Column(name: 'id', type: 'integer')]
    #[ORM\Id]
    #[ORM\GeneratedValue]
    protected ?int $id = null;

    #[ORM\ManyToOne(targetEntity: TicketCategory::class)]
    #[ORM\JoinColumn(name: 'category_id', referencedColumnName: 'id')]
    protected TicketCategory $category;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    protected TicketCategory $user;
}
