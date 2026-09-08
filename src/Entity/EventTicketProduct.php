<?php

namespace App\Entity;

use App\Repository\EventTicketProductRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Extension of a Product with productType = event_ticket — buying one adds the member to the
 * linked Event if it has a price. Optionally tied to a MembershipType to make it a discounted
 * "members only" price — see isOpenTicket().
 */
#[ORM\Entity(repositoryClass: EventTicketProductRepository::class)]
class EventTicketProduct
{
    #[ORM\Id]
    #[ORM\OneToOne(targetEntity: Product::class, inversedBy: 'eventTicketProduct')]
    #[ORM\JoinColumn(name: 'product_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private Product $product;

    #[ORM\ManyToOne(targetEntity: Event::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Event $event;

    /** Restricts this ticket's price to members holding this type — null means an "open" ticket anyone can buy. */
    #[ORM\ManyToOne(targetEntity: MembershipType::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?MembershipType $membershipType = null;

    public function __construct(Product $product)
    {
        $this->product = $product;
    }

    public function getProduct(): Product
    {
        return $this->product;
    }

    public function getEvent(): Event
    {
        return $this->event;
    }

    public function setEvent(Event $event): static
    {
        $this->event = $event;
        return $this;
    }

    public function getMembershipType(): ?MembershipType
    {
        return $this->membershipType;
    }

    public function setMembershipType(?MembershipType $membershipType): static
    {
        $this->membershipType = $membershipType;
        return $this;
    }

    public function isOpenTicket(): bool
    {
        return $this->membershipType === null;
    }
}
