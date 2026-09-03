<?php

namespace FluentCart\App\Services\PDF;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Default block-editor PDF structures for FluentCart receipts.
 *
 * Design system (A4 Portrait) — minimal:
 *   TEXT    #111111  primary text
 *   META    #666666  labels / secondary text
 *   BORDER  #DDDDDD  dividers
 *   WHITE   #FFFFFF
 */
class DefaultPdfStructures
{
    private const TEXT   = '#111111';
    private const META   = '#666666';
    private const BORDER = '#DDDDDD';
    private const WHITE  = '#FFFFFF';

    /**
     * Default meta for all PDF templates.
     */
    public static function getDefaultMeta(): array
    {
        return [
            '_fluent_cart_paper_size'        => 'A4',
            '_fluent_cart_orientation'        => 'Portrait',
            '_fluent_cart_font'              => 'DejaVuSans',
            '_fluent_cart_watermark_enabled'  => 0,
            '_fluent_cart_watermark_text'     => 'PAID',
        ];
    }

    // ── Order Receipt ────────────────────────────────────────────────────────

    /**
     * @return array{content:string, blocks:array, meta:array}
     */
    public static function getDefaultReceiptStructure(): array
    {
        $blocks = self::buildReceiptBlocks();
        return [
            'content' => self::blocksToContent($blocks),
            'blocks'  => $blocks,
            'meta'    => self::getDefaultMeta(),
        ];
    }

    private static function buildReceiptBlocks(): array
    {
        $blocks = [];

        $blocks[] = self::header(__('RECEIPT', 'fluent-cart'));
        $blocks[] = self::addressColumns();
        $blocks[] = self::metaTable([
            __('Order Number', 'fluent-cart')   => '{{order.invoice_no}}',
            __('Order Date', 'fluent-cart')     => '{{order.created_at}}',
            __('Payment Method', 'fluent-cart') => '{{order.payment_method_title}}',
            __('Payment Status', 'fluent-cart') => '{{order.payment_status}}',
        ]);
        $blocks[] = self::itemsTable();
        $blocks[] = self::summaryTable([
            __('Subtotal', 'fluent-cart') => '{{order.subtotal_formatted}}',
            __('Discount', 'fluent-cart') => '{{order.discount_total_formatted}}',
            __('Fees', 'fluent-cart')     => '{{order.fee_lines}}',
            __('Tax', 'fluent-cart')      => '{{order.tax_breakdown}}',
            __('Shipping', 'fluent-cart') => '{{order.shipping_total_formatted}}',
        ], __('Total', 'fluent-cart'), '{{order.total_amount_formatted}}');
        $blocks[] = self::footer(__('Thank you for your purchase!', 'fluent-cart'));

        return $blocks;
    }

    // ── Renewal Receipt ───────────────────────────────────────────────────────

    /**
     * @return array{content:string, blocks:array, meta:array}
     */
    public static function getDefaultRenewalReceiptStructure(): array
    {
        $blocks = self::buildRenewalReceiptBlocks();
        return [
            'content' => self::blocksToContent($blocks),
            'blocks'  => $blocks,
            'meta'    => self::getDefaultMeta(),
        ];
    }

    private static function buildRenewalReceiptBlocks(): array
    {
        $blocks = [];

        $blocks[] = self::header(__('RENEWAL RECEIPT', 'fluent-cart'));
        $blocks[] = self::addressColumns();
        $blocks[] = self::metaTable([
            __('Order Number', 'fluent-cart')   => '{{order.invoice_no}}',
            __('Renewal Date', 'fluent-cart')   => '{{order.created_at}}',
            __('Payment Method', 'fluent-cart') => '{{order.payment_method_title}}',
            __('Payment Status', 'fluent-cart') => '{{order.payment_status}}',
        ]);
        $blocks[] = self::itemsTable();
        $blocks[] = self::summaryTable([
            __('Subtotal', 'fluent-cart') => '{{order.subtotal_formatted}}',
            __('Discount', 'fluent-cart') => '{{order.discount_total_formatted}}',
            __('Fees', 'fluent-cart')     => '{{order.fee_lines}}',
            __('Tax', 'fluent-cart')      => '{{order.tax_breakdown}}',
        ], __('Total', 'fluent-cart'), '{{order.total_amount_formatted}}');
        $blocks[] = self::footer(__('Thank you for your continued subscription!', 'fluent-cart'));

        return $blocks;
    }

    // ── Refund Notice ─────────────────────────────────────────────────────────

    /**
     * @return array{content:string, blocks:array, meta:array}
     */
    public static function getDefaultRefundNoticeStructure(): array
    {
        $blocks = self::buildRefundNoticeBlocks();
        $meta = self::getDefaultMeta();
        $meta['_fluent_cart_watermark_text'] = 'REFUNDED';
        return [
            'content' => self::blocksToContent($blocks),
            'blocks'  => $blocks,
            'meta'    => $meta,
        ];
    }

    private static function buildRefundNoticeBlocks(): array
    {
        $blocks = [];

        $blocks[] = self::header(__('REFUND NOTICE', 'fluent-cart'));
        $blocks[] = self::addressColumns(__('REFUND TO', 'fluent-cart'));
        $blocks[] = self::metaTable([
            __('Order Number', 'fluent-cart')   => '{{order.invoice_no}}',
            __('Order Date', 'fluent-cart')     => '{{order.created_at}}',
            __('Payment Method', 'fluent-cart') => '{{order.payment_method_title}}',
        ]);
        $blocks[] = self::itemsTable();
        $blocks[] = self::summaryTable([
            __('Original Total', 'fluent-cart') => '{{order.total_amount_formatted}}',
        ], __('Refund Amount', 'fluent-cart'), '{{order.total_refund}}');
        $blocks[] = self::footer(__('Your refund has been processed. Please allow a few business days for the amount to appear in your account.', 'fluent-cart'));

        return $blocks;
    }

    // ── Proforma Invoice ──────────────────────────────────────────────────────

    /**
     * @return array{content:string, blocks:array, meta:array}
     */
    public static function getDefaultProformaInvoiceStructure(): array
    {
        $blocks = self::buildProformaInvoiceBlocks();
        $meta = self::getDefaultMeta();
        $meta['_fluent_cart_watermark_enabled'] = 0;
        $meta['_fluent_cart_watermark_text']    = '';
        return [
            'content' => self::blocksToContent($blocks),
            'blocks'  => $blocks,
            'meta'    => $meta,
        ];
    }

    private static function buildProformaInvoiceBlocks(): array
    {
        $blocks = [];

        $blocks[] = self::header(__('INVOICE', 'fluent-cart'));
        $blocks[] = self::addressColumns();
        $blocks[] = self::metaTable([
            __('Invoice Number', 'fluent-cart') => '{{order.invoice_no}}',
            __('Invoice Date', 'fluent-cart')   => '{{order.created_at}}',
            __('Payment Method', 'fluent-cart') => '{{order.payment_method_title}}',
            __('Payment Status', 'fluent-cart') => '{{order.payment_status}}',
        ]);
        $blocks[] = self::itemsTable();
        $blocks[] = self::summaryTable([
            __('Subtotal', 'fluent-cart') => '{{order.subtotal_formatted}}',
            __('Discount', 'fluent-cart') => '{{order.discount_total_formatted}}',
            __('Fees', 'fluent-cart')     => '{{order.fee_lines}}',
            __('Tax', 'fluent-cart')      => '{{order.tax_breakdown}}',
            __('Shipping', 'fluent-cart') => '{{order.shipping_total_formatted}}',
        ], __('Amount Due', 'fluent-cart'), '{{order.total_amount_formatted}}');
        $blocks[] = self::footer(__('Thank you for your order. Please complete the payment to process your order.', 'fluent-cart'));

        return $blocks;
    }

    // ── Shared block builders ─────────────────────────────────────────────────

    /**
     * Document header: logo left, title right, bottom rule.
     */
    private static function header(string $title): array
    {
        return self::block('fluent-cart/receipt-header', ['title' => $title]);
    }

    /**
     * Two-column FROM / BILL TO address block.
     */
    private static function addressColumns(?string $billingLabel = null): array
    {
        if ($billingLabel === null) {
            $billingLabel = __('BILL TO', 'fluent-cart');
        }

        return self::block('fluent-cart/receipt-addresses', ['billingLabel' => $billingLabel]);
    }

    /**
     * Key/value meta rows (order number, date, etc.).
     *
     * @param array<string,string> $rows label => smartcode
     */
    private static function metaTable(array $rows): array
    {
        $rowsData = [];
        foreach ($rows as $label => $value) {
            $rowsData[] = ['label' => $label, 'value' => $value];
        }

        return self::block('fluent-cart/receipt-meta', ['rows' => $rowsData]);
    }

    /**
     * Line-items table (delegated to the order variable).
     */
    private static function itemsTable(): array
    {
        // No inner content. The block is dynamic — it registers with
        // `save: () => null` and renders through
        // ReceiptItemTableBlockEditor::render(), which ignores $content and emits
        // its own {{order.pdf_items_table}} placeholder built from these
        // attributes. Passing content here made blocksToContent() serialise an
        // open/close pair with `{{order.items_table}}` inside it, and the editor
        // then failed to validate the saved markup against a save() that produces
        // nothing. Every other receipt block already passes null and serialises
        // self-closing.
        return self::block('fluent-cart/receipt-item-table', [
            'headerBg'    => self::WHITE,
            'headerColor' => self::TEXT,
            'bodyColor'   => self::TEXT,
            'borderColor' => self::BORDER,
            'fontSize'    => 11,
        ]);
    }

    /**
     * Payment summary with optional sub-rows and a bold total row.
     *
     * @param array<string,string> $rows label => smartcode
     */
    private static function summaryTable(array $rows, string $totalLabel, string $totalValue): array
    {
        $rowsData = [];
        foreach ($rows as $label => $value) {
            $rowsData[] = ['label' => $label, 'value' => $value];
        }

        return self::block('fluent-cart/receipt-payment-summary', [
            'rows'       => $rowsData,
            'totalLabel' => $totalLabel,
            'totalValue' => $totalValue,
        ]);
    }

    /**
     * Footer note, centred, small text.
     */
    private static function footer(string $text): array
    {
        return self::block('fluent-cart/receipt-footer', ['text' => $text]);
    }

    // ── Low-level helpers ─────────────────────────────────────────────────────

    private static function block(string $name, array $attrs = [], ?string $content = null, array $innerBlocks = []): array
    {
        return [
            'blockName'    => $name,
            'attrs'        => $attrs,
            'innerHTML'    => $content ?? '',
            'innerContent' => $content !== null ? [$content] : [],
            'innerBlocks'  => $innerBlocks,
        ];
    }

    /**
     * Serialize blocks array to Gutenberg block comment format.
     */
    public static function blocksToContent(array $blocks): string
    {
        $out = '';
        foreach ($blocks as $block) {
            $name        = $block['blockName'];
            $attrs       = $block['attrs'] ?? [];
            $innerBlocks = $block['innerBlocks'] ?? [];
            $innerHTML   = $block['innerHTML'] ?? '';

            $attrJson = !empty($attrs) ? ' ' . wp_json_encode($attrs) : '';

            if (!empty($innerBlocks)) {
                $out .= "<!-- wp:{$name}{$attrJson} -->\n";
                $out .= $innerHTML ? "{$innerHTML}\n" : '';
                $out .= self::blocksToContent($innerBlocks);
                $out .= "<!-- /wp:{$name} -->\n\n";
            } else {
                if ($innerHTML) {
                    $out .= "<!-- wp:{$name}{$attrJson} -->\n";
                    $out .= "{$innerHTML}\n";
                    $out .= "<!-- /wp:{$name} -->\n\n";
                } else {
                    $out .= "<!-- wp:{$name}{$attrJson} /-->\n\n";
                }
            }
        }
        return $out;
    }
}
