<?php

namespace App\Service\Mailer;

use App\Entity\PurchaseOrder;
use App\Entity\User;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

/** Emails an approved purchase order to the supplier as a PDF attachment, with replies going to the admin who sent it. */
class PurchaseOrderMailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        #[Autowire('%env(MAILER_FROM)%')]      private readonly string $mailerFrom,
        #[Autowire('%env(MAILER_FROM_NAME)%')] private readonly string $mailerFromName,
    ) {}

    /** @throws \LogicException if the supplier has no email address */
    public function sendToSupplier(PurchaseOrder $po, string $pdfContent, string $filename, ?string $message, User $sentBy): string
    {
        $supplier = $po->getSupplier();
        $to       = $supplier->getEmail();
        if (!$to) {
            throw new \LogicException($supplier->getSupplierName() . ' has no email address — add one to their contact, or place the order another way and mark it ordered.');
        }

        $email = (new TemplatedEmail())
            ->from(new Address($this->mailerFrom, $this->mailerFromName))
            ->to($to)
            ->subject('Purchase order ' . $po->getNumber() . ' from Y Wal')
            ->htmlTemplate('email/purchase_order.html.twig')
            ->textTemplate('email/purchase_order.txt.twig')
            ->context([
                'po'       => $po,
                'supplier' => $supplier,
                'message'  => $message,
                'sentBy'   => $sentBy,
            ])
            ->attach($pdfContent, $filename, 'application/pdf');

        if ($sentBy->getEmail()) {
            $email->replyTo(new Address($sentBy->getEmail(), $sentBy->getDisplayName()));
        }

        $this->mailer->send($email);

        return $to;
    }
}
