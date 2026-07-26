/* Presence — Portfolio : visionneuse plein écran avec navigation entre photos. */
(function () {
    'use strict';
    var CFG = window.PORTFOLIO_GALLERY || { photos: [] };
    var $ = function (s) { return document.querySelector(s); };

    var PortfolioGallery = {
        photos: CFG.photos,
        index: 0,

        open: function (i) {
            this.index = i;
            this.render();
            $('#pfg-lightbox').hidden = false;
            document.body.style.overflow = 'hidden';
        },
        close: function () {
            $('#pfg-lightbox').hidden = true;
            document.body.style.overflow = '';
        },
        prev: function () {
            this.index = (this.index - 1 + this.photos.length) % this.photos.length;
            this.render();
        },
        next: function () {
            this.index = (this.index + 1) % this.photos.length;
            this.render();
        },
        render: function () {
            var photo = this.photos[this.index];
            $('#pfg-lightbox-img').src = photo.url;
            $('#pfg-lightbox-count').textContent = (this.index + 1) + ' / ' + this.photos.length;
            var multiple = this.photos.length > 1;
            document.querySelectorAll('.pfg-lightbox__nav').forEach(function (btn) { btn.hidden = !multiple; });
        }
    };

    window.PortfolioGallery = PortfolioGallery;

    document.addEventListener('keydown', function (event) {
        var lightbox = $('#pfg-lightbox');
        if (!lightbox || lightbox.hidden) return;
        if (event.key === 'Escape') PortfolioGallery.close();
        if (event.key === 'ArrowLeft') PortfolioGallery.prev();
        if (event.key === 'ArrowRight') PortfolioGallery.next();
    });
})();
