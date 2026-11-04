<?php

namespace App\Twig;

use App\Repository\PurchaseOrderRepository;
use App\Service\AvatarUploader;
use App\Service\ProductImageUploader;
use App\Service\SubscribeGuard;
use App\Service\UkPhoneFormatter;
use App\Service\UnsubscribeToken;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

class AppExtension extends AbstractExtension
{
    public function __construct(
        private readonly UrlGeneratorInterface $router,
        private readonly UnsubscribeToken $unsubscribeToken,
        private readonly UkPhoneFormatter $ukPhoneFormatter,
        private readonly AvatarUploader $avatarUploader,
        private readonly ProductImageUploader $productImageUploader,
        private readonly PurchaseOrderRepository $purchaseOrderRepository,
        private readonly SubscribeGuard $subscribeGuard,
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('unsubscribe_url', $this->unsubscribeUrl(...)),
            new TwigFunction('avatar_url', $this->avatarUploader->getUrl(...)),
            new TwigFunction('product_image_url', $this->productImageUploader->getUrl(...)),
            new TwigFunction('subscribe_form_token', $this->subscribeGuard->issueToken(...)),
            new TwigFunction('purchase_orders_awaiting_approval', $this->purchaseOrderRepository->countAwaitingApproval(...)),
        ];
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('uk_phone', $this->ukPhoneFormatter->format(...)),
            new TwigFilter('tel_link', $this->ukPhoneFormatter->dialable(...)),
            new TwigFilter('email_blocks', $this->emailBlocks(...)),
        ];
    }

    /** Tags that, when a line starts with one, begin a block of HTML that must not be wrapped in <p>. */
    private const EMAIL_BLOCK_START = 'table|thead|tbody|tfoot|tr|td|th|ul|ol|li|div|blockquote|h[1-6]|hr|pre|p|center';

    /** Container tags whose open/close balance decides where a multi-line HTML block ends. */
    private const EMAIL_BLOCK_CONTAINER = 'table|ul|ol|div|blockquote|pre|center';

    /**
     * Splits a hand-written email body into renderable chunks: plain lines become paragraphs
     * ('p'), blank lines become spacers ('gap'), and HTML blocks (tables, lists…), even across
     * several lines, are kept whole ('html'). Wrapping each line of a table in its own <p>
     * produced invalid markup that browsers repair but Gmail mangles.
     *
     * $cellStyle is prepended to every <td>/<th> in HTML blocks, because Gmail doesn't reliably
     * apply <head> CSS; a cell's own style still wins as it comes later in the attribute.
     *
     * @return list<array{type: 'p'|'gap'|'html', html?: string}>
     */
    public function emailBlocks(string $body, string $cellStyle = ''): array
    {
        $blocks = [];
        $raw = null;
        $depth = 0;

        foreach (preg_split('/\R/', $body) as $line) {
            $trimmed = trim($line);

            if ($raw !== null || preg_match('/^<(?:' . self::EMAIL_BLOCK_START . ')\b/i', $trimmed)) {
                $raw = $raw === null ? $line : $raw . "\n" . $line;
                $depth += preg_match_all('/<(?:' . self::EMAIL_BLOCK_CONTAINER . ')\b/i', $line)
                    - preg_match_all('#</(?:' . self::EMAIL_BLOCK_CONTAINER . ')\s*>#i', $line);

                if ($depth <= 0) {
                    $blocks[] = ['type' => 'html', 'html' => $this->styleCells($raw, $cellStyle)];
                    $raw = null;
                    $depth = 0;
                }
                continue;
            }

            $blocks[] = $trimmed === '' ? ['type' => 'gap'] : ['type' => 'p', 'html' => $line];
        }

        if ($raw !== null) {
            $blocks[] = ['type' => 'html', 'html' => $this->styleCells($raw, $cellStyle)];
        }

        return $blocks;
    }

    private function styleCells(string $html, string $cellStyle): string
    {
        if ($cellStyle === '') {
            return $html;
        }
        $cellStyle = rtrim($cellStyle, '; ') . ';';

        return preg_replace_callback('/<(td|th)\b([^>]*)>/i', static function (array $m) use ($cellStyle): string {
            $attrs = $m[2];
            if (preg_match('/\bstyle\s*=\s*(["\'])/i', $attrs, $q, PREG_OFFSET_CAPTURE)) {
                $at = $q[1][1] + 1;
                $attrs = substr($attrs, 0, $at) . $cellStyle . substr($attrs, $at);
            } else {
                $attrs .= ' style="' . htmlspecialchars($cellStyle, ENT_QUOTES) . '"';
            }

            return '<' . $m[1] . $attrs . '>';
        }, $html);
    }

    public function unsubscribeUrl(string $email): string
    {
        return $this->router->generate('app_unsubscribe', [
            'email' => UnsubscribeToken::normaliseEmail($email),
            'token' => $this->unsubscribeToken->generate($email),
        ], UrlGeneratorInterface::ABSOLUTE_URL);
    }
}
