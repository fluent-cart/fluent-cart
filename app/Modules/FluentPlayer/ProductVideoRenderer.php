<?php

namespace FluentCart\App\Modules\FluentPlayer;

use FluentCart\App\Models\Product;
use FluentCart\App\Vite;

/**
 * Gallery markup for a product's FluentPlayer videos: one thumbnail in the
 * strip and one hidden inline player per visible media.
 *
 * The strip is interleaved with the images: each video carries the number of
 * images it sits behind (ProductVideoSettings), so the caller renders the
 * videos due before image N, then image N, and flushes the rest at the end.
 */
class ProductVideoRenderer
{
    protected $product;

    protected $config;

    protected $videos = null;

    protected $showFirstByDefault = false;

    /** @var array<int, bool> media ids already written into the strip. */
    protected $renderedThumbs = [];

    public function __construct(Product $product)
    {
        $this->product = $product;
        $this->config = FluentPlayerBridge::isActive() ? ProductVideoSettings::fromProduct($product) : null;
    }

    public function getMediaIds(): array
    {
        return $this->config ? $this->config['media_ids'] : [];
    }

    /**
     * Only media that is both visible and rendered by FluentPlayer: a thumb
     * without a player would leave the gallery blank when clicked.
     *
     * @return array<int, array> media id => summary + 'player' html.
     */
    public function getVideos(): array
    {
        if ($this->videos !== null) {
            return $this->videos;
        }

        $this->videos = [];
        if ($this->config === null || !$this->isFullPageRender()) {
            return $this->videos;
        }

        foreach ($this->getMediaIds() as $mediaId) {
            $media = FluentPlayerBridge::findVisibleMedia($mediaId);
            if (!$media) {
                continue;
            }

            $player = FluentPlayerBridge::renderPlayer($mediaId, 'fct-product-video-player');
            if ($player === '') {
                continue;
            }

            $this->videos[$mediaId] = FluentPlayerBridge::summarize($media) + ['player' => $player];
        }

        return $this->videos;
    }

    public function isAvailable(): bool
    {
        return count($this->getVideos()) > 0;
    }

    /**
     * A product without gallery images opens on its first video instead of a
     * placeholder image, so the video is visible without a click.
     */
    public function showFirstByDefault(bool $show): void
    {
        $this->showFirstByDefault = $show;
    }

    /**
     * @return array<int, int|null> media id => images that precede it (null = last).
     */
    public function getPositions(): array
    {
        return $this->config ? ProductVideoSettings::positionMap($this->config) : [];
    }

    /**
     * The video the page opens on: the one the admin dragged in front of every
     * image, or — for a product with no image at all — the first video.
     */
    public function getDefaultMediaId(): int
    {
        $videos = $this->getVideos();
        if (!$videos) {
            return 0;
        }

        $positions = $this->getPositions();
        foreach (array_keys($videos) as $mediaId) {
            if (isset($positions[$mediaId]) && $positions[$mediaId] === 0) {
                return (int) $mediaId;
            }
        }

        if (!$this->showFirstByDefault) {
            return 0;
        }

        $ids = array_keys($videos);

        return (int) $ids[0];
    }

    /**
     * FluentPlayer's player config is only printed by the normal page footer, so
     * admin-ajax / REST fragments (shop quick-view) must not carry a player.
     */
    protected function isFullPageRender(): bool
    {
        if (function_exists('wp_doing_ajax') && wp_doing_ajax()) {
            return false;
        }

        if (defined('REST_REQUEST') && REST_REQUEST) {
            return false;
        }

        return true;
    }

    protected function getVideoTitle(array $video): string
    {
        $title = trim((string) $video['title']);

        return $title !== '' ? $title : (string) $this->product->post_title;
    }

    /**
     * Never the product photo: a neutral placeholder when FluentPlayer has no poster.
     */
    protected function getPosterUrl(array $video): string
    {
        $poster = (string) $video['poster'];

        return $poster !== '' ? $poster : Vite::getAssetUrl('images/placeholder.svg');
    }

    protected function playIconSvg(): string
    {
        return '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false" width="24" height="24"><path d="M8 5v14l11-7z"/></svg>';
    }

    /**
     * The video thumbs due before the image about to be rendered: every video
     * the admin placed after `$imageIndex` images or fewer. Videos with no
     * position wait for the closing renderThumbControls() flush.
     */
    public function renderThumbControlsBefore(int $imageIndex, bool $claimFocus = false): void
    {
        $positions = $this->getPositions();

        foreach ($this->getVideos() as $mediaId => $video) {
            if (isset($this->renderedThumbs[$mediaId])) {
                continue;
            }
            $position = isset($positions[$mediaId]) ? $positions[$mediaId] : null;
            if ($position === null || $position > $imageIndex) {
                continue;
            }
            $this->renderThumbControl((int) $mediaId, $video, $claimFocus);
            $claimFocus = false;
        }
    }

    /**
     * Every video thumb not written yet — the ones placed after the last image
     * and, when the strip was cut short by a thumbnail limit, the rest.
     */
    public function renderThumbControls(bool $claimFocus = false): void
    {
        foreach ($this->getVideos() as $mediaId => $video) {
            if (isset($this->renderedThumbs[$mediaId])) {
                continue;
            }
            $this->renderThumbControl((int) $mediaId, $video, $claimFocus);
            $claimFocus = false;
        }
    }

    /**
     * Shares the thumb-control contract so keyboard navigation and variation
     * filtering treat video thumbs like images; ImageGallery.js keys on
     * data-fct-video-media-id.
     *
     * The strip uses a roving tabindex. When no image thumb holds the slot
     * (a video-only product) the first video thumb takes it, otherwise the
     * strip could never be reached with the keyboard. The default video's
     * thumb is also selected, mirroring the default image thumb.
     */
    protected function renderThumbControl(int $mediaId, array $video, bool $claimFocus): void
    {
        $this->renderedThumbs[$mediaId] = true;

        $defaultId = $this->getDefaultMediaId();
        $title = $this->getVideoTitle($video);
        $isSelected = $mediaId === $defaultId;
        $tabindex = ($claimFocus || $isSelected) ? '0' : '-1';
        ?>
        <button
                type="button"
                class="fct-gallery-thumb-control-button fct-gallery-video-thumb<?php echo $isSelected ? ' active' : ''; ?>"
                data-fluent-cart-thumb-control-button
                data-variation-id="0"
                data-fct-video-media-id="<?php echo esc_attr((string) $mediaId); ?>"
                aria-label="<?php
                    /* translators: %1$s: video title */
                    echo esc_attr(sprintf(__('Play video: %1$s', 'fluent-cart'), $title));
                ?>"
                aria-pressed="<?php echo $isSelected ? 'true' : 'false'; ?>"
                tabindex="<?php echo esc_attr($tabindex); ?>"
        >
            <img
                    class="fct-gallery-control-thumb"
                    src="<?php echo esc_url($this->getPosterUrl($video)); ?>"
                    alt=""
            />
            <span class="fct-gallery-video-thumb__badge" aria-hidden="true"><?php echo $this->playIconSvg(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG ?></span>
        </button>
        <?php
    }

    /**
     * Hidden until ImageGallery.js marks the matching container `is-active`,
     * except the default video, which is active from the start.
     */
    public function renderInlinePlayers(): void
    {
        $defaultId = $this->getDefaultMediaId();

        foreach ($this->getVideos() as $mediaId => $video) {
            ?>
            <div class="fct-product-gallery-video<?php echo $mediaId === $defaultId ? ' is-active' : ''; ?>"
                 data-fct-product-gallery-video
                 data-fct-video-media-id="<?php echo esc_attr((string) $mediaId); ?>">
                <?php echo $video['player']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- FluentPlayer renders and escapes its own player markup ?>
            </div>
            <?php
        }
    }
}
