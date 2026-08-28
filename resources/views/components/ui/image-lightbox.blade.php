{{-- ADASI Interactive Image Lightbox Modal --}}
<div
    id="adasiImageLightbox"
    class="adasi-image-lightbox"
    role="dialog"
    aria-modal="true"
    aria-hidden="true"
    tabindex="-1"
    style="display: none;"
>
    {{-- Dimmed backdrop --}}
    <div class="adasi-image-lightbox__backdrop" data-lightbox-close></div>

    {{-- Lightbox Container --}}
    <div class="adasi-image-lightbox__container">
        {{-- Header Bar --}}
        <div class="adasi-image-lightbox__header">
            <div class="adasi-image-lightbox__title-wrap">
                <span class="adasi-image-lightbox__counter" id="adasiImageLightboxCounter" style="display: none;"></span>
                <span class="adasi-image-lightbox__title" id="adasiImageLightboxTitle">Image Preview</span>
            </div>
            <div class="adasi-image-lightbox__header-actions">
                <a
                    href="#"
                    id="adasiImageLightboxDownload"
                    download
                    class="adasi-image-lightbox__btn"
                    title="Download Image"
                >
                    <x-ui.icon name="download" size="sm" />
                </a>
                <button
                    type="button"
                    class="adasi-image-lightbox__btn adasi-image-lightbox__btn--close"
                    data-lightbox-close
                    title="Close (Esc)"
                    aria-label="Close"
                >
                    <x-ui.icon name="x" size="sm" />
                </button>
            </div>
        </div>

        {{-- Side Navigation Arrows (Shown when multiple photos exist) --}}
        <button
            type="button"
            class="adasi-image-lightbox__nav-btn adasi-image-lightbox__nav-btn--prev"
            id="adasiLightboxNavPrev"
            title="Previous Image (←)"
            aria-label="Previous Image"
            style="display: none;"
        >
            <x-ui.icon name="chevron-left" size="md" />
        </button>

        <button
            type="button"
            class="adasi-image-lightbox__nav-btn adasi-image-lightbox__nav-btn--next"
            id="adasiLightboxNavNext"
            title="Next Image (→)"
            aria-label="Next Image"
            style="display: none;"
        >
            <x-ui.icon name="chevron-right" size="md" />
        </button>

        {{-- Main Viewer Area --}}
        <div class="adasi-image-lightbox__stage" id="adasiImageLightboxStage">
            <div class="adasi-image-lightbox__image-wrapper" id="adasiImageLightboxWrapper">
                <img
                    src=""
                    alt="Preview"
                    id="adasiImageLightboxImg"
                    class="adasi-image-lightbox__img"
                    draggable="false"
                >
            </div>
        </div>

        {{-- Floating Bottom Control Toolbar --}}
        <div class="adasi-image-lightbox__toolbar" role="toolbar" aria-label="Image controls">
            <button
                type="button"
                class="adasi-image-lightbox__toolbar-btn"
                id="adasiLightboxToolbarPrev"
                title="Previous Image (←)"
                aria-label="Previous Image"
                style="display: none;"
            >
                <x-ui.icon name="chevron-left" size="sm" />
            </button>

            <button
                type="button"
                class="adasi-image-lightbox__toolbar-btn"
                id="adasiLightboxZoomOut"
                title="Zoom Out (-)"
                aria-label="Zoom Out"
            >
                <x-ui.icon name="minus" size="sm" />
            </button>

            <span class="adasi-image-lightbox__zoom-level" id="adasiLightboxZoomText" aria-live="polite">100%</span>

            <button
                type="button"
                class="adasi-image-lightbox__toolbar-btn"
                id="adasiLightboxZoomIn"
                title="Zoom In (+)"
                aria-label="Zoom In"
            >
                <x-ui.icon name="plus" size="sm" />
            </button>

            <div class="adasi-image-lightbox__toolbar-divider"></div>

            <button
                type="button"
                class="adasi-image-lightbox__toolbar-btn"
                id="adasiLightboxZoomReset"
                title="Reset Zoom & Pan (100%)"
                aria-label="Reset Zoom"
            >
                <x-ui.icon name="rotate-ccw" size="sm" />
            </button>

            <button
                type="button"
                class="adasi-image-lightbox__toolbar-btn"
                id="adasiLightboxToolbarNext"
                title="Next Image (→)"
                aria-label="Next Image"
                style="display: none;"
            >
                <x-ui.icon name="chevron-right" size="sm" />
            </button>
        </div>
    </div>
</div>

<style>
.adasi-image-lightbox {
    position: fixed;
    inset: 0;
    z-index: 10500;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    user-select: none;
    -webkit-user-select: none;
    opacity: 0;
    transition: opacity 0.2s ease-out;
    touch-action: none;
}

.adasi-image-lightbox.is-open {
    opacity: 1;
}

.adasi-image-lightbox__backdrop {
    position: absolute;
    inset: 0;
    background-color: rgba(8, 12, 22, 0.90);
    backdrop-filter: blur(10px);
    -webkit-backdrop-filter: blur(10px);
}

.adasi-image-lightbox__container {
    position: relative;
    width: 100%;
    height: 100%;
    display: flex;
    flex-direction: column;
    z-index: 1;
}

.adasi-image-lightbox__header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0.875rem 1.5rem;
    background: linear-gradient(180deg, rgba(0, 0, 0, 0.8) 0%, rgba(0, 0, 0, 0) 100%);
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    z-index: 20;
    pointer-events: auto;
}

.adasi-image-lightbox__title-wrap {
    display: flex;
    align-items: center;
    gap: 0.625rem;
    max-width: 70vw;
}

.adasi-image-lightbox__counter {
    display: inline-block;
    padding: 0.2rem 0.55rem;
    font-size: 0.75rem;
    font-weight: 600;
    color: #e2e8f0;
    background: rgba(255, 255, 255, 0.15);
    border: 1px solid rgba(255, 255, 255, 0.2);
    border-radius: 9999px;
    white-space: nowrap;
}

.adasi-image-lightbox__title {
    color: #ffffff;
    font-size: 0.9375rem;
    font-weight: 600;
    text-shadow: 0 1px 3px rgba(0, 0, 0, 0.6);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.adasi-image-lightbox__header-actions {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.adasi-image-lightbox__btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 38px;
    height: 38px;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.14);
    color: #ffffff;
    border: 1px solid rgba(255, 255, 255, 0.25);
    cursor: pointer;
    text-decoration: none;
    transition: background 0.15s ease, transform 0.15s ease;
    backdrop-filter: blur(4px);
}

.adasi-image-lightbox__btn:hover {
    background: rgba(255, 255, 255, 0.28);
    color: #ffffff;
    transform: scale(1.06);
}

.adasi-image-lightbox__nav-btn {
    position: absolute;
    top: 50%;
    transform: translateY(-50%);
    z-index: 20;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 46px;
    height: 46px;
    border-radius: 50%;
    background: rgba(18, 24, 43, 0.75);
    border: 1px solid rgba(255, 255, 255, 0.25);
    color: #ffffff;
    cursor: pointer;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.4);
    backdrop-filter: blur(8px);
    -webkit-backdrop-filter: blur(8px);
    transition: background 0.15s ease, transform 0.15s ease, opacity 0.15s ease;
}

.adasi-image-lightbox__nav-btn:hover {
    background: rgba(30, 41, 69, 0.95);
    transform: translateY(-50%) scale(1.08);
}

.adasi-image-lightbox__nav-btn--prev {
    left: 1.25rem;
}

.adasi-image-lightbox__nav-btn--next {
    right: 1.25rem;
}

.adasi-image-lightbox__stage {
    flex: 1 1 auto;
    width: 100%;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    position: relative;
    cursor: grab;
    touch-action: none;
}

.adasi-image-lightbox__stage.is-dragging {
    cursor: grabbing;
}

.adasi-image-lightbox__image-wrapper {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    transform-origin: center center;
    will-change: transform;
}

.adasi-image-lightbox__img {
    max-width: 90vw;
    max-height: 82vh;
    object-fit: contain;
    border-radius: 6px;
    box-shadow: 0 14px 50px rgba(0, 0, 0, 0.65);
    pointer-events: none;
    transition: opacity 0.15s ease-out;
}

.adasi-image-lightbox__toolbar {
    position: absolute;
    bottom: 1.75rem;
    left: 50%;
    transform: translateX(-50%);
    display: flex;
    align-items: center;
    gap: 0.375rem;
    padding: 0.375rem 0.75rem;
    background: rgba(16, 22, 39, 0.88);
    border: 1px solid rgba(255, 255, 255, 0.22);
    border-radius: 9999px;
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.5);
    backdrop-filter: blur(12px);
    -webkit-backdrop-filter: blur(12px);
    z-index: 20;
}

.adasi-image-lightbox__toolbar-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 34px;
    height: 34px;
    border-radius: 50%;
    background: transparent;
    border: 0;
    color: #ffffff;
    cursor: pointer;
    transition: background 0.15s ease, transform 0.1s ease;
}

.adasi-image-lightbox__toolbar-btn:hover {
    background: rgba(255, 255, 255, 0.22);
    color: #ffffff;
    transform: scale(1.08);
}

.adasi-image-lightbox__zoom-level {
    color: #ffffff;
    font-size: 0.8125rem;
    font-weight: 600;
    font-variant-numeric: tabular-nums;
    padding: 0 0.375rem;
    min-width: 50px;
    text-align: center;
}

.adasi-image-lightbox__toolbar-divider {
    width: 1px;
    height: 18px;
    background: rgba(255, 255, 255, 0.2);
    margin: 0 2px;
}
</style>

<script>
(() => {
    let currentZoom = 1.0;
    let panX = 0;
    let panY = 0;
    let isDragging = false;
    let dragStartX = 0;
    let dragStartY = 0;
    let initialPanX = 0;
    let initialPanY = 0;

    // Gallery state
    let galleryItems = [];
    let currentGalleryIndex = 0;

    // Pointer events tracking for multi-touch pinch on mobile
    const activePointers = new Map();
    let initialPinchDistance = 0;
    let initialPinchZoom = 1.0;

    const MIN_ZOOM = 0.3;
    const MAX_ZOOM = 5.0;
    const ZOOM_STEP = 0.25;

    const $lightbox = () => document.getElementById('adasiImageLightbox');
    const $wrapper = () => document.getElementById('adasiImageLightboxWrapper');
    const $stage = () => document.getElementById('adasiImageLightboxStage');
    const $img = () => document.getElementById('adasiImageLightboxImg');
    const $title = () => document.getElementById('adasiImageLightboxTitle');
    const $counter = () => document.getElementById('adasiImageLightboxCounter');
    const $zoomText = () => document.getElementById('adasiLightboxZoomText');
    const $download = () => document.getElementById('adasiImageLightboxDownload');
    const $navPrev = () => document.getElementById('adasiLightboxNavPrev');
    const $navNext = () => document.getElementById('adasiLightboxNavNext');
    const $tbPrev = () => document.getElementById('adasiLightboxToolbarPrev');
    const $tbNext = () => document.getElementById('adasiLightboxToolbarNext');

    function applyTransform(smooth = false) {
        const wrapper = $wrapper();
        if (!wrapper) return;
        wrapper.style.transition = smooth ? 'transform 0.2s cubic-bezier(0.16, 1, 0.3, 1)' : 'none';
        wrapper.style.transform = `translate3d(${panX}px, ${panY}px, 0) scale(${currentZoom})`;
        const zoomText = $zoomText();
        if (zoomText) {
            zoomText.textContent = `${Math.round(currentZoom * 100)}%`;
        }
    }

    function setZoom(newZoom, smooth = true) {
        currentZoom = Math.min(Math.max(newZoom, MIN_ZOOM), MAX_ZOOM);
        if (currentZoom <= 1.0) {
            panX = 0;
            panY = 0;
        }
        applyTransform(smooth);
    }

    function zoomToPoint(newZoom, clientX, clientY) {
        const stage = $stage();
        if (!stage) return;
        const rect = stage.getBoundingClientRect();
        const stageCenterX = rect.left + rect.width / 2;
        const stageCenterY = rect.top + rect.height / 2;

        const mouseX = clientX - stageCenterX;
        const mouseY = clientY - stageCenterY;

        const targetZoom = Math.min(Math.max(newZoom, MIN_ZOOM), MAX_ZOOM);
        const factor = targetZoom / currentZoom;

        panX = mouseX - (mouseX - panX) * factor;
        panY = mouseY - (mouseY - panY) * factor;
        currentZoom = targetZoom;

        if (currentZoom <= 1.0) {
            panX = 0;
            panY = 0;
        }

        applyTransform(false);
    }

    function zoomIn() {
        setZoom(currentZoom + ZOOM_STEP, true);
    }

    function zoomOut() {
        setZoom(currentZoom - ZOOM_STEP, true);
    }

    function resetZoom() {
        currentZoom = 1.0;
        panX = 0;
        panY = 0;
        applyTransform(true);
    }

    function updateGalleryUI() {
        const hasMultiple = galleryItems.length > 1;
        const counterEl = $counter();
        const navPrev = $navPrev();
        const navNext = $navNext();
        const tbPrev = $tbPrev();
        const tbNext = $tbNext();

        if (counterEl) {
            if (hasMultiple) {
                counterEl.textContent = `${currentGalleryIndex + 1} / ${galleryItems.length}`;
                counterEl.style.display = 'inline-block';
            } else {
                counterEl.style.display = 'none';
            }
        }

        [navPrev, navNext, tbPrev, tbNext].forEach(el => {
            if (el) el.style.display = hasMultiple ? 'inline-flex' : 'none';
        });
    }

    function showGalleryItem(index) {
        if (!galleryItems.length || index < 0 || index >= galleryItems.length) return;
        currentGalleryIndex = index;
        const item = galleryItems[index];

        const img = $img();
        const titleEl = $title();
        const downloadEl = $download();

        if (img) {
            img.style.opacity = '0.3';
            img.src = item.src;
            img.onload = () => {
                img.style.opacity = '1';
            };
        }

        if (titleEl) titleEl.textContent = item.title;
        if (downloadEl) {
            downloadEl.href = item.downloadUrl || item.src;
            downloadEl.setAttribute('download', item.title || 'image');
        }

        resetZoom();
        updateGalleryUI();
    }

    function nextImage() {
        if (galleryItems.length <= 1) return;
        const nextIdx = (currentGalleryIndex + 1) % galleryItems.length;
        showGalleryItem(nextIdx);
    }

    function prevImage() {
        if (galleryItems.length <= 1) return;
        const prevIdx = (currentGalleryIndex - 1 + galleryItems.length) % galleryItems.length;
        showGalleryItem(prevIdx);
    }

    function openLightbox(src, title = 'Image Preview', downloadUrl = null, gallery = null, startIndex = 0) {
        const modal = $lightbox();
        if (!modal) return;

        if (Array.isArray(gallery) && gallery.length > 0) {
            galleryItems = gallery;
            currentGalleryIndex = Math.max(0, Math.min(startIndex, gallery.length - 1));
        } else {
            galleryItems = [{ src, title, downloadUrl: downloadUrl || src }];
            currentGalleryIndex = 0;
        }

        showGalleryItem(currentGalleryIndex);

        modal.style.display = 'flex';
        modal.setAttribute('aria-hidden', 'false');
        requestAnimationFrame(() => {
            modal.classList.add('is-open');
        });
        document.body.style.overflow = 'hidden';
    }

    function closeLightbox() {
        const modal = $lightbox();
        if (!modal) return;
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
        setTimeout(() => {
            modal.style.display = 'none';
            const img = $img();
            if (img) img.src = '';
            document.body.style.overflow = '';
            galleryItems = [];
        }, 200);
    }

    window.AdasiLightbox = {
        open: openLightbox,
        close: closeLightbox,
        zoomIn,
        zoomOut,
        resetZoom,
        next: nextImage,
        prev: prevImage
    };

    document.addEventListener('DOMContentLoaded', () => {
        const stage = $stage();
        const modal = $lightbox();

        // Control Buttons
        document.getElementById('adasiLightboxZoomIn')?.addEventListener('click', zoomIn);
        document.getElementById('adasiLightboxZoomOut')?.addEventListener('click', zoomOut);
        document.getElementById('adasiLightboxZoomReset')?.addEventListener('click', resetZoom);

        // Navigation triggers
        $navPrev()?.addEventListener('click', prevImage);
        $navNext()?.addEventListener('click', nextImage);
        $tbPrev()?.addEventListener('click', prevImage);
        $tbNext()?.addEventListener('click', nextImage);

        // Close triggers
        modal?.addEventListener('click', (e) => {
            if (e.target.closest('[data-lightbox-close]') || e.target === modal) {
                closeLightbox();
            }
        });

        // Touchpad 2-finger scroll and Mouse Wheel handling
        stage?.addEventListener('wheel', (e) => {
            e.preventDefault();

            if (e.ctrlKey) {
                // Pinch-to-zoom on touchpad OR Ctrl + Mouse Wheel
                const zoomFactor = Math.exp(-e.deltaY * 0.008);
                zoomToPoint(currentZoom * zoomFactor, e.clientX, e.clientY);
            } else {
                // 2-finger pan on touchpad / mousepad or regular scroll
                panX -= e.deltaX;
                panY -= e.deltaY;
                applyTransform(false);
            }
        }, { passive: false });

        // Double click / tap to toggle zoom towards clicked point
        stage?.addEventListener('dblclick', (e) => {
            if (e.target.closest('.adasi-image-lightbox__toolbar') || e.target.closest('.adasi-image-lightbox__header') || e.target.closest('.adasi-image-lightbox__nav-btn')) return;
            if (currentZoom > 1.1) {
                resetZoom();
            } else {
                zoomToPoint(2.2, e.clientX, e.clientY);
            }
        });

        // Pointer Events (Mouse drag and Touch drag/pinch)
        stage?.addEventListener('pointerdown', (e) => {
            if (e.target.closest('.adasi-image-lightbox__toolbar') || e.target.closest('.adasi-image-lightbox__header') || e.target.closest('.adasi-image-lightbox__nav-btn')) return;
            activePointers.set(e.pointerId, e);

            if (activePointers.size === 1) {
                // Single pointer pan (Mouse or 1-finger touch)
                if (e.button !== undefined && e.button !== 0) return;
                isDragging = true;
                dragStartX = e.clientX;
                dragStartY = e.clientY;
                initialPanX = panX;
                initialPanY = panY;
                stage.classList.add('is-dragging');
                stage.setPointerCapture(e.pointerId);
            } else if (activePointers.size === 2) {
                // 2-finger touch pinch zoom
                isDragging = false;
                const points = Array.from(activePointers.values());
                const dx = points[0].clientX - points[1].clientX;
                const dy = points[0].clientY - points[1].clientY;
                initialPinchDistance = Math.hypot(dx, dy);
                initialPinchZoom = currentZoom;
            }
        });

        stage?.addEventListener('pointermove', (e) => {
            if (!activePointers.has(e.pointerId)) return;
            activePointers.set(e.pointerId, e);

            if (activePointers.size === 1 && isDragging) {
                const deltaX = e.clientX - dragStartX;
                const deltaY = e.clientY - dragStartY;
                panX = initialPanX + deltaX;
                panY = initialPanY + deltaY;
                applyTransform(false);
            } else if (activePointers.size === 2) {
                const points = Array.from(activePointers.values());
                const dx = points[0].clientX - points[1].clientX;
                const dy = points[0].clientY - points[1].clientY;
                const distance = Math.hypot(dx, dy);
                if (initialPinchDistance > 0) {
                    const midX = (points[0].clientX + points[1].clientX) / 2;
                    const midY = (points[0].clientY + points[1].clientY) / 2;
                    const targetZoom = initialPinchZoom * (distance / initialPinchDistance);
                    zoomToPoint(targetZoom, midX, midY);
                }
            }
        });

        const handlePointerEnd = (e) => {
            activePointers.delete(e.pointerId);
            if (stage.hasPointerCapture(e.pointerId)) {
                stage.releasePointerCapture(e.pointerId);
            }
            if (activePointers.size === 0) {
                isDragging = false;
                stage.classList.remove('is-dragging');
            }
        };

        stage?.addEventListener('pointerup', handlePointerEnd);
        stage?.addEventListener('pointercancel', handlePointerEnd);

        // Keyboard Shortcuts
        window.addEventListener('keydown', (e) => {
            if (!modal || modal.style.display === 'none') return;
            if (e.key === 'Escape') {
                closeLightbox();
            } else if (e.key === 'ArrowRight' || e.key === 'd' || e.key === 'D') {
                nextImage();
            } else if (e.key === 'ArrowLeft' || e.key === 'a' || e.key === 'A') {
                prevImage();
            } else if (e.key === '+' || e.key === '=') {
                zoomIn();
            } else if (e.key === '-' || e.key === '_') {
                zoomOut();
            } else if (e.key === '0' || e.key === ' ') {
                resetZoom();
            }
        });

        // Global Event Delegation for any image preview triggers with automatic Gallery grouping
        document.addEventListener('click', (e) => {
            const trigger = e.target.closest('[data-lightbox-image], a.image-preview-trigger, a[data-lightbox-preview], a[href*="/attachments/"]:has(img), a.qc-evidence-preview');
            if (!trigger) return;

            const href = trigger.getAttribute('href') || trigger.dataset.lightboxImage || trigger.dataset.src;
            const img = trigger.querySelector('img');

            if (href && (img || /\.(jpe?g|png|webp|gif|bmp|svg)(\?.*)?$/i.test(href) || trigger.hasAttribute('data-lightbox-image') || trigger.classList.contains('image-preview-trigger'))) {
                e.preventDefault();
                e.stopPropagation();

                // Find all image triggers within the same parent section or container for gallery mode
                const container = trigger.closest('.row, .tw-grid, .card, [data-lightbox-group]') || document;
                const triggers = Array.from(container.querySelectorAll('[data-lightbox-image], a.image-preview-trigger, a[data-lightbox-preview], a[href*="/attachments/"]:has(img), a.qc-evidence-preview'));

                const gallery = triggers.map(t => {
                    const tHref = t.getAttribute('href') || t.dataset.lightboxImage || t.dataset.src;
                    const tImg = t.querySelector('img');
                    const tTitle = t.getAttribute('title') || t.dataset.title || tImg?.getAttribute('alt') || 'Evidence Image';
                    return { src: tHref, title: tTitle, downloadUrl: tHref };
                }).filter(item => item.src);

                const currentIndex = triggers.indexOf(trigger);
                openLightbox(href, trigger.getAttribute('title') || trigger.dataset.title || img?.getAttribute('alt') || 'Evidence Image', href, gallery, currentIndex >= 0 ? currentIndex : 0);
            }
        });
    });
})();
</script>
