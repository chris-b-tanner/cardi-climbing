<?php

namespace App\Service;

use App\Entity\Asset;
use App\Entity\Note;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Writes an asset's history into its notes panel — a system note (addedBy = null, so only an owner
 * can delete it) for each significant change, naming whoever made it. There's no separate event log:
 * the notes trail is the history. Callers flush.
 */
class AssetHistory
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    /** Records a change to each of the tracked fields that differs between {before} (from snapshot()) and {asset} now. */
    public function recordChanges(Asset $asset, array $before, ?User $by): void
    {
        $after = $this->snapshot($asset);
        $lines = [];

        foreach ($this->trackedFields() as $field => $label) {
            if ($before[$field] === $after[$field]) {
                continue;
            }

            $from = $this->format($field, $before[$field]);
            $to   = $this->format($field, $after[$field]);

            $lines[] = match ($field) {
                'location' => 'Moved from ' . $from . ' to ' . $to,
                default    => $label . ' changed from ' . $from . ' to ' . $to,
            };
        }

        if ($lines !== []) {
            $this->record($asset, implode("\n", $lines), $by);
        }
    }

    /** @return array<string, mixed> The tracked fields' current values, to pass back to recordChanges() after editing. */
    public function snapshot(Asset $asset): array
    {
        return [
            'location'         => $asset->getLocation(),
            'status'           => $asset->getStatus(),
            'quantity'         => $asset->getQuantity(),
            'purchaseCost'     => $asset->getPurchaseCost(),
            'replacementValue' => $asset->getReplacementValue(),
        ];
    }

    public function record(Asset $asset, string $text, ?User $by): void
    {
        $note = new Note();
        $note->setNoteable($asset);
        $note->setContent($by ? $text . ' — ' . $by->getDisplayName() : $text);
        $note->setAddedBy(null);

        $this->em->persist($note);
    }

    /** @return array<string, string> */
    private function trackedFields(): array
    {
        return [
            'location'         => 'Location',
            'status'           => 'Status',
            'quantity'         => 'Quantity',
            'purchaseCost'     => 'Purchase cost',
            'replacementValue' => 'Replacement value',
        ];
    }

    private function format(string $field, mixed $value): string
    {
        if ($value === null || $value === '') {
            return $field === 'location' ? 'no location' : 'not set';
        }

        return match ($field) {
            'status'                           => Asset::STATUS_LABELS[$value] ?? (string) $value,
            'purchaseCost', 'replacementValue' => '£' . $value,
            default                            => (string) $value,
        };
    }
}
