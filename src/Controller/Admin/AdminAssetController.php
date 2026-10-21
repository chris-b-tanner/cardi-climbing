<?php

namespace App\Controller\Admin;

use App\Entity\Asset;
use App\Entity\AssetFile;
use App\Entity\Note;
use App\Entity\User;
use App\Repository\AssetRepository;
use App\Repository\NoteRepository;
use App\Service\AssetFileUploader;
use App\Service\AssetHistory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The asset register — things the centre owns and uses but doesn't sell (see assets-spec.md). The
 * team can add, edit, move and change the status of assets and upload files; money (purchase cost,
 * replacement value) and disposal are admin-only, the same rule as sales and purchase prices.
 */
#[Route('/admin/assets')]
#[IsGranted('ROLE_TEAM')]
class AdminAssetController extends AbstractController
{
    /** Statuses that can be set directly — disposal goes through dispose() instead, since it needs a reason. */
    private const SETTABLE_STATUSES = [Asset::STATUS_IN_USE, Asset::STATUS_IN_STORAGE, Asset::STATUS_OUT_OF_SERVICE];

    /** The register's status filter: '' = current (not disposed), 'all' = everything. */
    private const STATUS_FILTERS = ['', Asset::STATUS_IN_USE, Asset::STATUS_IN_STORAGE, Asset::STATUS_OUT_OF_SERVICE, Asset::STATUS_DISPOSED, 'all'];

    public function __construct(
        private readonly AssetRepository $assetRepository,
        private readonly AssetHistory $history,
    ) {}

    #[Route('', name: 'app_admin_assets')]
    public function index(Request $request): Response
    {
        $filters = $this->parseFilters($request);
        $assets  = $this->assetRepository->search($filters['q'], $filters['category'], $filters['location'], $filters['status']);

        $params = [
            'assets'    => $assets,
            'totals'    => $this->totals($assets),
            'filters'   => $filters,
            'locations' => $this->assetRepository->findLocations(),
        ];

        if ($request->isXmlHttpRequest()) {
            return $this->render('admin/assets/_list.html.twig', $params);
        }

        return $this->render('admin/assets/index.html.twig', $params);
    }

    #[Route('/export', name: 'app_admin_assets_export')]
    public function export(Request $request): StreamedResponse
    {
        $filters = $this->parseFilters($request);
        $assets  = $this->assetRepository->search($filters['q'], $filters['category'], $filters['location'], $filters['status']);

        $response = new StreamedResponse(function () use ($assets) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, ['ID', 'Name', 'Category', 'Quantity', 'Identifier', 'Location', 'Status', 'Acquired', 'How acquired', 'Purchase cost (net)', 'Replacement value (net)', 'Last valued', 'Warranty until', 'Disposed', 'Disposal reason']);

            foreach ($assets as $asset) {
                fputcsv($handle, [
                    $asset->getId(),
                    $asset->getName(),
                    $asset->getCategoryLabel(),
                    $asset->getQuantity(),
                    $asset->getIdentifier(),
                    $asset->getLocation(),
                    $asset->getStatusLabel(),
                    $asset->getAcquiredAt()?->format('Y-m-d'),
                    $asset->getAcquisitionTypeLabel(),
                    $asset->getPurchaseCost(),
                    $asset->getReplacementValue(),
                    $asset->getReplacementValueUpdatedAt()?->format('Y-m-d'),
                    $asset->getWarrantyUntil()?->format('Y-m-d'),
                    $asset->getDisposedAt()?->format('Y-m-d'),
                    $asset->getDisposalReasonLabel(),
                ]);
            }

            fclose($handle);
        });

        $response->headers->set('Content-Type', 'text/csv');
        $response->headers->set('Content-Disposition', 'attachment; filename="assets-' . (new \DateTimeImmutable())->format('Y-m-d') . '.csv"');

        return $response;
    }

    /** Insurance report — every current asset with its replacement value, grouped by category and by location. A standalone print view. */
    #[Route('/insurance', name: 'app_admin_assets_insurance')]
    public function insurance(): Response
    {
        $assets = $this->assetRepository->search();

        $byCategory = [];
        $byLocation = [];
        foreach ($assets as $asset) {
            $byCategory[$asset->getCategoryLabel()][] = $asset;
            $byLocation[$asset->getLocation() ?? 'No location'][] = $asset;
        }
        ksort($byCategory);
        ksort($byLocation);

        return $this->render('admin/assets/insurance.html.twig', [
            'byCategory' => array_map(fn (array $group) => ['assets' => $group, 'totals' => $this->totals($group)], $byCategory),
            'byLocation' => array_map(fn (array $group) => ['assets' => $group, 'totals' => $this->totals($group)], $byLocation),
            'totals'     => $this->totals($assets),
            'generatedAt' => new \DateTimeImmutable(),
        ]);
    }

    #[Route('/new', name: 'app_admin_asset_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        $asset = new Asset();
        $error = null;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_asset_new', $request->request->get('_csrf_token'))) {
                $this->addFlash('error', 'Access denied.');
                return $this->redirectToRoute('app_home');
            }

            $error = $this->applyForm($request, $asset, isNew: true);

            if (!$error) {
                /** @var User $user */
                $user = $this->getUser();
                $asset->setCreatedBy($user);

                $em->persist($asset);
                $em->flush();

                $this->history->record($asset, 'Added to the register (' . $asset->getAcquisitionTypeLabel() . ')', $user);
                $em->flush();

                $this->addFlash('success', 'Asset added.');

                // "Save and add another" keeps the fields a stocktake usually repeats.
                if ($request->request->get('addAnother')) {
                    return $this->redirectToRoute('app_admin_asset_new', array_filter([
                        'category'        => $asset->getCategory(),
                        'location'        => $asset->getLocation(),
                        'acquisitionType' => $asset->getAcquisitionType(),
                    ]));
                }

                return $this->redirectToRoute('app_admin_asset_show', ['id' => $asset->getId()]);
            }
        } else {
            $category = $request->query->get('category', '');
            if (array_key_exists($category, Asset::CATEGORY_LABELS)) {
                $asset->setCategory($category);
            }
            $acquisitionType = $request->query->get('acquisitionType', '');
            if (array_key_exists($acquisitionType, Asset::ACQUISITION_LABELS)) {
                $asset->setAcquisitionType($acquisitionType);
            }
            $location = trim($request->query->get('location', ''));
            $asset->setLocation($location !== '' ? mb_substr($location, 0, 100) : null);
        }

        return $this->render('admin/assets/new.html.twig', [
            'asset'     => $asset,
            'error'     => $error,
            'locations' => $this->assetRepository->findLocations(),
        ]);
    }

    #[Route('/{id}', name: 'app_admin_asset_show', requirements: ['id' => '\d+'])]
    public function show(Asset $asset, NoteRepository $noteRepository, AssetFileUploader $uploader): Response
    {
        $photoUrls = [];
        foreach ($asset->getFiles() as $file) {
            if ($file->isImage()) {
                $photoUrls[$file->getId()] = $uploader->getViewUrl($file);
            }
        }

        return $this->render('admin/assets/show.html.twig', [
            'asset'     => $asset,
            'notes'     => $noteRepository->findForNoteable(Note::TYPE_ASSET, $asset->getId()),
            'photoUrls' => $photoUrls,
            'locations' => $this->assetRepository->findLocations(),
            'statuses'  => self::SETTABLE_STATUSES,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_admin_asset_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, Asset $asset, EntityManagerInterface $em): Response
    {
        if ($asset->isDisposed()) {
            $this->addFlash('error', 'A disposed asset can\'t be edited.');
            return $this->redirectToRoute('app_admin_asset_show', ['id' => $asset->getId()]);
        }

        $error = null;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_asset_edit_' . $asset->getId(), $request->request->get('_csrf_token'))) {
                $this->addFlash('error', 'Access denied.');
                return $this->redirectToRoute('app_home');
            }

            $before = $this->history->snapshot($asset);
            $error  = $this->applyForm($request, $asset, isNew: false);

            if (!$error) {
                /** @var User $user */
                $user = $this->getUser();
                $this->history->recordChanges($asset, $before, $user);
                $em->flush();

                $this->addFlash('success', 'Asset updated.');
                return $this->redirectToRoute('app_admin_asset_show', ['id' => $asset->getId()]);
            }
        }

        return $this->render('admin/assets/edit.html.twig', [
            'asset'     => $asset,
            'error'     => $error,
            'locations' => $this->assetRepository->findLocations(),
        ]);
    }

    #[Route('/{id}/move', name: 'app_admin_asset_move', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function move(Request $request, Asset $asset, EntityManagerInterface $em): Response
    {
        if (!$this->assertChangeable($request, $asset, 'admin_asset_move_')) {
            return $this->redirectToRoute('app_admin_asset_show', ['id' => $asset->getId()]);
        }

        $location = trim($request->request->get('location', ''));
        if (mb_strlen($location) > 100) {
            $this->addFlash('error', 'Keep the location under 100 characters.');
            return $this->redirectToRoute('app_admin_asset_show', ['id' => $asset->getId()]);
        }

        $before = $this->history->snapshot($asset);
        $asset->setLocation($location !== '' ? $location : null);

        /** @var User $user */
        $user = $this->getUser();
        $this->history->recordChanges($asset, $before, $user);
        $em->flush();

        $this->addFlash('success', 'Location updated.');
        return $this->redirectToRoute('app_admin_asset_show', ['id' => $asset->getId()]);
    }

    #[Route('/{id}/status', name: 'app_admin_asset_status', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function status(Request $request, Asset $asset, EntityManagerInterface $em): Response
    {
        if (!$this->assertChangeable($request, $asset, 'admin_asset_status_')) {
            return $this->redirectToRoute('app_admin_asset_show', ['id' => $asset->getId()]);
        }

        $status = $request->request->get('status', '');
        if (!in_array($status, self::SETTABLE_STATUSES, true)) {
            $this->addFlash('error', 'Choose a status.');
            return $this->redirectToRoute('app_admin_asset_show', ['id' => $asset->getId()]);
        }

        $before = $this->history->snapshot($asset);
        $asset->setStatus($status);

        /** @var User $user */
        $user = $this->getUser();
        $this->history->recordChanges($asset, $before, $user);
        $em->flush();

        $this->addFlash('success', 'Status updated.');
        return $this->redirectToRoute('app_admin_asset_show', ['id' => $asset->getId()]);
    }

    /** Takes some or all of an asset off the register. A required note says why; part of a batch reduces it, all of it marks the asset disposed. Admin only. */
    #[Route('/{id}/dispose', name: 'app_admin_asset_dispose', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function dispose(Request $request, Asset $asset, EntityManagerInterface $em): Response
    {
        if (!$this->assertChangeable($request, $asset, 'admin_asset_dispose_')) {
            return $this->redirectToRoute('app_admin_asset_show', ['id' => $asset->getId()]);
        }

        $qtyRaw = $request->request->get('qty', (string) $asset->getQuantity());
        $reason = $request->request->get('reason', '');
        $note   = trim($request->request->get('note', ''));
        $on     = $this->parseDate($request->request->get('disposedAt', '')) ?? new \DateTimeImmutable('today');

        if (!ctype_digit((string) $qtyRaw)) {
            $this->addFlash('error', 'Enter how many to dispose of.');
            return $this->redirectToRoute('app_admin_asset_show', ['id' => $asset->getId()]);
        }
        if (!array_key_exists($reason, Asset::DISPOSAL_LABELS)) {
            $this->addFlash('error', 'Choose a reason.');
            return $this->redirectToRoute('app_admin_asset_show', ['id' => $asset->getId()]);
        }
        if ($note === '') {
            $this->addFlash('error', 'A note is required when disposing of an asset.');
            return $this->redirectToRoute('app_admin_asset_show', ['id' => $asset->getId()]);
        }
        if (mb_strlen($note) > 255) {
            $this->addFlash('error', 'Keep the note under 255 characters.');
            return $this->redirectToRoute('app_admin_asset_show', ['id' => $asset->getId()]);
        }
        if ($on > new \DateTimeImmutable('today')) {
            $this->addFlash('error', 'The disposal date can\'t be in the future.');
            return $this->redirectToRoute('app_admin_asset_show', ['id' => $asset->getId()]);
        }

        $qty          = (int) $qtyRaw;
        $wasQuantity  = $asset->getQuantity();

        try {
            $asset->dispose($qty, $reason, $note, $on);
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
            return $this->redirectToRoute('app_admin_asset_show', ['id' => $asset->getId()]);
        }

        $summary = $asset->isDisposed()
            ? 'Disposed of' . ($wasQuantity > 1 ? ' (all ' . $wasQuantity . ')' : '')
            : 'Disposed of ' . $qty . ' of ' . $wasQuantity . ', ' . $asset->getQuantity() . ' left';

        /** @var User $user */
        $user = $this->getUser();
        $this->history->record($asset, $summary . ' on ' . $on->format('d M Y') . ': ' . Asset::DISPOSAL_LABELS[$reason] . '. ' . $note, $user);
        $em->flush();

        $this->addFlash('success', $asset->isDisposed() ? 'Asset disposed of.' : 'Batch reduced.');
        return $this->redirectToRoute('app_admin_asset_show', ['id' => $asset->getId()]);
    }

    #[Route('/{id}/files/upload', name: 'app_admin_asset_file_upload', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function uploadFile(Request $request, Asset $asset, EntityManagerInterface $em, AssetFileUploader $uploader): Response
    {
        if (!$this->isCsrfTokenValid('upload_asset_file_' . $asset->getId(), $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Access denied.');
            return $this->redirectToRoute('app_home');
        }

        $file = $request->files->get('file');
        if (!$file) {
            $this->addFlash('error', 'Please choose a file to upload.');
            return $this->redirectToRoute('app_admin_asset_show', ['id' => $asset->getId()]);
        }

        /** @var User $user */
        $user   = $this->getUser();
        $result = $uploader->upload($asset, $file, $request->request->get('kind', AssetFile::KIND_OTHER), $user);

        if (is_string($result)) {
            $this->addFlash('error', $result);
        } else {
            $em->persist($result);
            $em->flush();
            $this->addFlash('success', 'File uploaded.');
        }

        return $this->redirectToRoute('app_admin_asset_show', ['id' => $asset->getId()]);
    }

    #[Route('/{id}/files/{fileId}/download', name: 'app_admin_asset_file_download', requirements: ['id' => '\d+', 'fileId' => '\d+'], methods: ['GET'])]
    public function downloadFile(Asset $asset, int $fileId, AssetFileUploader $uploader): Response
    {
        $file = $this->findFile($asset, $fileId);
        if (!$file) {
            $this->addFlash('error', 'File not found.');
            return $this->redirectToRoute('app_admin_asset_show', ['id' => $asset->getId()]);
        }

        return $this->redirect($uploader->getDownloadUrl($file));
    }

    /** Removes a file from an asset. Admin only, like a member's files — photos and receipts can be evidence for an insurance claim. */
    #[Route('/{id}/files/{fileId}/delete', name: 'app_admin_asset_file_delete', requirements: ['id' => '\d+', 'fileId' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function deleteFile(Request $request, Asset $asset, int $fileId, EntityManagerInterface $em, AssetFileUploader $uploader): Response
    {
        $file = $this->findFile($asset, $fileId);
        if (!$file) {
            $this->addFlash('error', 'File not found.');
            return $this->redirectToRoute('app_admin_asset_show', ['id' => $asset->getId()]);
        }

        if (!$this->isCsrfTokenValid('delete_asset_file_' . $file->getId(), $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Access denied.');
            return $this->redirectToRoute('app_home');
        }

        $uploader->remove($file);
        $asset->getFiles()->removeElement($file);
        $em->remove($file);
        $em->flush();

        $this->addFlash('success', 'File removed.');
        return $this->redirectToRoute('app_admin_asset_show', ['id' => $asset->getId()]);
    }

    /**
     * Populates {asset} from the new/edit form. Returns an error message, or null when it's valid.
     * Every check runs before anything is set, so on an error the asset is left untouched and the
     * form re-renders from the submitted values.
     * Purchase cost and replacement value are only read for admins; for anyone else they're left
     * exactly as they were. Status is only set on a new asset — after that it changes through
     * status() or dispose(), which record why.
     */
    private function applyForm(Request $request, Asset $asset, bool $isNew): ?string
    {
        $name            = trim($request->request->get('name', ''));
        $category        = $request->request->get('category', '');
        $description     = trim($request->request->get('description', ''));
        $quantityRaw     = trim($request->request->get('quantity', '1'));
        $identifier      = trim($request->request->get('identifier', ''));
        $location        = trim($request->request->get('location', ''));
        $acquisitionType = $request->request->get('acquisitionType', '');
        $acquiredAtRaw   = trim($request->request->get('acquiredAt', ''));
        $warrantyRaw     = trim($request->request->get('warrantyUntil', ''));

        if ($name === '') {
            return 'Enter a name.';
        }
        if (mb_strlen($name) > 150) {
            return 'Keep the name under 150 characters.';
        }
        if (!array_key_exists($category, Asset::CATEGORY_LABELS)) {
            return 'Choose a category.';
        }
        if (!ctype_digit($quantityRaw) || (int) $quantityRaw < 1) {
            return 'Enter a quantity of at least 1.';
        }
        if (mb_strlen($identifier) > 100 || mb_strlen($location) > 100) {
            return 'Keep the identifier and location under 100 characters.';
        }
        if (!array_key_exists($acquisitionType, Asset::ACQUISITION_LABELS)) {
            return 'Choose how it was acquired.';
        }

        $acquiredAt = $this->parseDate($acquiredAtRaw);
        if ($acquiredAtRaw !== '' && $acquiredAt === null) {
            return 'Enter a valid acquired date.';
        }
        if ($acquiredAt !== null && $acquiredAt > new \DateTimeImmutable('today')) {
            return 'The acquired date can\'t be in the future.';
        }

        $warrantyUntil = $this->parseDate($warrantyRaw);
        if ($warrantyRaw !== '' && $warrantyUntil === null) {
            return 'Enter a valid warranty date.';
        }

        $status = $request->request->get('status', Asset::STATUS_IN_USE);
        if ($isNew && !in_array($status, self::SETTABLE_STATUSES, true)) {
            return 'Choose a status.';
        }

        if ($this->isGranted('ROLE_ADMIN')) {
            [$purchaseCost, $costError] = $this->parseMoney($request->request->get('purchaseCost', ''), 'purchase cost');
            [$replacementValue, $valueError] = $this->parseMoney($request->request->get('replacementValue', ''), 'replacement value');
            if ($costError || $valueError) {
                return $costError ?? $valueError;
            }

            // A new asset's replacement value defaults to what it cost, as the spec says.
            if ($isNew && $replacementValue === null) {
                $replacementValue = $purchaseCost;
            }

            $asset->setPurchaseCost($purchaseCost);
            $asset->setReplacementValue($replacementValue);
        }

        if ($isNew) {
            $asset->setStatus($status);
        }
        $asset->setName($name);
        $asset->setCategory($category);
        $asset->setDescription($description !== '' ? $description : null);
        $asset->setQuantity((int) $quantityRaw);
        $asset->setIdentifier($identifier !== '' ? $identifier : null);
        $asset->setLocation($location !== '' ? $location : null);
        $asset->setAcquisitionType($acquisitionType);
        $asset->setAcquiredAt($acquiredAt);
        $asset->setWarrantyUntil($warrantyUntil);

        return null;
    }

    /** @return array{0: ?string, 1: ?string} [the amount as a 2dp string or null when blank, an error message or null] */
    private function parseMoney(string $raw, string $label): array
    {
        $raw = trim(str_replace(['£', ','], '', $raw));
        if ($raw === '') {
            return [null, null];
        }
        if (!is_numeric($raw) || (float) $raw < 0 || (float) $raw >= 100000000) {
            return [null, 'Enter a valid ' . $label . '.'];
        }

        return [number_format((float) $raw, 2, '.', ''), null];
    }

    private function parseDate(string $raw): ?\DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
        return $date && $date->format('Y-m-d') === $raw ? $date : null;
    }

    /** CSRF check plus "not already disposed" for the quick actions on the asset page. Adds the flash message itself. */
    private function assertChangeable(Request $request, Asset $asset, string $tokenPrefix): bool
    {
        if (!$this->isCsrfTokenValid($tokenPrefix . $asset->getId(), $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Access denied.');
            return false;
        }

        if ($asset->isDisposed()) {
            $this->addFlash('error', 'This asset has been disposed of.');
            return false;
        }

        return true;
    }

    /** @return array{q: string, category: string, location: string, status: string} */
    private function parseFilters(Request $request): array
    {
        $category = $request->query->get('category', '');
        $status   = $request->query->get('status', '');

        return [
            'q'        => trim($request->query->get('q', '')),
            'category' => array_key_exists($category, Asset::CATEGORY_LABELS) ? $category : '',
            'location' => trim($request->query->get('location', '')),
            'status'   => in_array($status, self::STATUS_FILTERS, true) ? $status : '',
        ];
    }

    /**
     * @param Asset[] $assets
     * @return array{count: int, quantity: int, purchaseCost: string, replacementValue: string, unvalued: int}
     */
    private function totals(array $assets): array
    {
        $quantity = 0;
        $cost     = 0.0;
        $value    = 0.0;
        $unvalued = 0;

        foreach ($assets as $asset) {
            $quantity += $asset->getQuantity();
            $cost     += (float) ($asset->getPurchaseCost() ?? 0);
            $value    += (float) ($asset->getReplacementValue() ?? 0);
            if ($asset->getReplacementValue() === null) {
                $unvalued++;
            }
        }

        return [
            'count'            => count($assets),
            'quantity'         => $quantity,
            'purchaseCost'     => number_format($cost, 2, '.', ''),
            'replacementValue' => number_format($value, 2, '.', ''),
            'unvalued'         => $unvalued,
        ];
    }

    private function findFile(Asset $asset, int $fileId): ?AssetFile
    {
        foreach ($asset->getFiles() as $file) {
            if ($file->getId() === $fileId) {
                return $file;
            }
        }

        return null;
    }
}
