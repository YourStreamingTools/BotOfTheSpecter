/*
   BotOfTheSpecter Roadmap - app.js
   Sidebar toggle + detail modal activity/attachment skeleton loaders
   (modal open/close and board SSR live in layout.php)
 */


(function () {
    'use strict';
    /* Sidebar toggle (mobile) */

    function initSidebar() {
        var hamburger = document.getElementById('sp-hamburger');
        var sidebar   = document.getElementById('sp-sidebar');
        var overlay   = document.getElementById('sp-sidebar-overlay');
        if (!hamburger || !sidebar) return;
        function openSidebar() {
            sidebar.classList.add('open');
            if (overlay) overlay.classList.add('open');
            document.body.style.overflow = 'hidden';
        }
        function closeSidebar() {
            sidebar.classList.remove('open');
            if (overlay) overlay.classList.remove('open');
            document.body.style.overflow = '';
        }
        hamburger.addEventListener('click', function () {
            if (sidebar.classList.contains('open')) {
                closeSidebar();
            } else {
                openSidebar();
            }
        });
        if (overlay) {
            overlay.addEventListener('click', closeSidebar);
        }
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') closeSidebar();
        });
    }
    /* Active nav link highlighting */

    function initActiveNav() {
        var path = window.location.pathname;
        var links = document.querySelectorAll('.sp-nav-link[href]');
        links.forEach(function (link) {
            var href = link.getAttribute('href');
            if (!href || href.startsWith('http') || href === '#') return;
            // normalize: strip query/hash
            var linkPath = href.split('?')[0].split('#')[0];
            if (linkPath === path || (linkPath !== '/' && path.endsWith(linkPath))) {
                link.classList.add('active');
            }
        });
    }

    function setBusy(el, busy) {
        if (!el) return;
        if (busy) el.setAttribute('aria-busy', 'true');
        else el.removeAttribute('aria-busy');
    }

    function skeletonActivityHtml() {
        var html = '';
        var widths = [
            ['w-40', 'w-90', 'w-70'],
            ['w-50', 'w-80', 'w-60'],
            ['w-45', 'w-90', 'w-55'],
            ['w-55', 'w-70', 'w-40']
        ];
        var i, w;
        for (i = 0; i < widths.length; i++) {
            w = widths[i];
            html += '<div class="sp-skeleton-stack" aria-hidden="true" style="margin-bottom:0.65rem;">' +
                '<span class="sp-skeleton sp-skeleton-line ' + w[0] + '"></span>' +
                '<span class="sp-skeleton sp-skeleton-line ' + w[1] + '"></span>' +
                '<span class="sp-skeleton sp-skeleton-line ' + w[2] + '"></span>' +
                '</div>';
        }
        return html;
    }

    function skeletonAttachmentsHtml() {
        var html = '';
        var i;
        for (i = 0; i < 3; i++) {
            html += '<div class="sp-skeleton-stack" aria-hidden="true" style="margin-bottom:0.75rem;padding:0.5rem 0;">' +
                '<span class="sp-skeleton sp-skeleton-line w-70"></span>' +
                '<span class="sp-skeleton sp-skeleton-line w-50"></span>' +
                '</div>';
        }
        return html;
    }

    /**
     * Replace layout.php loadActivity / loadAttachments with skeleton-aware
     * versions. app.js is defer; those globals are already defined by then.
     * Uses layout helpers: getFileIcon, isImage, escapeHtml, formatDateSydney.
     */
    function installDetailSkeletons() {
        if (typeof window.loadActivity === 'function' && !window.loadActivity.__spSkeleton) {
            window.loadActivity = function (itemId) {
                var sec = document.getElementById('commentsSection');
                if (!sec) return;
                setBusy(sec, true);
                sec.innerHTML = skeletonActivityHtml();
                var url = '../get-activity.php?item_id=' + encodeURIComponent(itemId);
                if (window.__ROADMAP_ADMIN_PAGE) url += '&admin=1';
                fetch(url)
                    .then(function (r) { return r.text(); })
                    .then(function (html) {
                        sec.innerHTML = html;
                        setBusy(sec, false);
                    })
                    .catch(function () {
                        sec.innerHTML = '<p style="color:var(--red);font-size:0.875rem;">Error loading activity</p>';
                        setBusy(sec, false);
                    });
            };
            window.loadActivity.__spSkeleton = true;
        }

        if (typeof window.loadAttachments === 'function' && !window.loadAttachments.__spSkeleton) {
            window.loadAttachments = function (itemId) {
                var sec = document.getElementById('attachmentsSection');
                if (!sec) return;
                setBusy(sec, true);
                sec.innerHTML = skeletonAttachmentsHtml();
                fetch('../admin/get-attachments.php?item_id=' + encodeURIComponent(itemId))
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (data.success && data.attachments && data.attachments.length > 0) {
                            var html = '';
                            data.attachments.forEach(function (att) {
                                var fileIcon = (typeof window.getFileIcon === 'function')
                                    ? window.getFileIcon(att.file_type)
                                    : 'fa-file';
                                var isImg = (typeof window.isImage === 'function')
                                    ? window.isImage(att.file_type)
                                    : false;
                                var esc = (typeof window.escapeHtml === 'function')
                                    ? window.escapeHtml
                                    : function (v) { return String(v || ''); };
                                var fmt = (typeof window.formatDateSydney === 'function')
                                    ? window.formatDateSydney
                                    : function (v) { return v || ''; };
                                var delBtn = att.can_delete
                                    ? '<button class="sp-btn sp-btn-danger sp-btn-xs sp-btn-icon delete-attachment-btn" data-attachment-id="' +
                                        att.id + '" data-item-id="' + itemId + '" title="Delete"><i class="fa-solid fa-trash-can"></i></button>'
                                    : '';
                                if (isImg) {
                                    html += '<div class="rm-attachment"><div class="rm-attachment-body"><div class="rm-attachment-meta">' +
                                        esc(att.file_name) + ' &middot; ' + esc(att.file_size_formatted) + ' &middot; ' +
                                        esc(att.uploaded_by) + ' &middot; ' + fmt(att.created_at) +
                                        '</div><img src="' + att.file_path + '" alt="' + esc(att.file_name) +
                                        '" class="rm-attachment-img zoom-image" data-filename="' + esc(att.file_name) +
                                        '"></div>' + delBtn + '</div>';
                                } else {
                                    html += '<div class="rm-attachment"><div class="rm-attachment-body"><div class="rm-attachment-meta">' +
                                        esc(att.file_size_formatted) + ' &middot; ' + esc(att.uploaded_by) + ' &middot; ' +
                                        fmt(att.created_at) + '</div><a href="' + att.file_path +
                                        '" download class="rm-attachment-name"><i class="fa-solid ' + fileIcon + '"></i> ' +
                                        esc(att.file_name) + '</a></div>' + delBtn + '</div>';
                                }
                            });
                            sec.innerHTML = html;
                            sec.querySelectorAll('.delete-attachment-btn').forEach(function (btn) {
                                btn.addEventListener('click', function () {
                                    if (!confirm('Delete this attachment?')) return;
                                    var fd = new FormData();
                                    fd.append('attachment_id', this.dataset.attachmentId);
                                    var csrfEl = document.querySelector('meta[name="csrf-token"]');
                                    fd.append('csrf_token', csrfEl ? csrfEl.getAttribute('content') : '');
                                    fetch('../admin/delete-attachment.php', { method: 'POST', body: fd })
                                        .then(function (r) { return r.json(); })
                                        .then(function (d) {
                                            if (d.success) window.loadAttachments(itemId);
                                            else alert('Error: ' + d.message);
                                        })
                                        .catch(function () { alert('Network error'); });
                                });
                            });
                            var zoomModal = document.getElementById('imageZoomModal');
                            var zoomImg = document.getElementById('zoomImageContent');
                            var zoomName = document.getElementById('zoomImageName');
                            sec.querySelectorAll('.zoom-image').forEach(function (img) {
                                img.addEventListener('click', function () {
                                    if (zoomImg) zoomImg.src = this.src;
                                    if (zoomName) zoomName.textContent = this.dataset.filename || '';
                                    if (zoomModal) zoomModal.classList.add('open');
                                });
                            });
                        } else {
                            sec.innerHTML = '<p style="color:var(--text-muted);font-size:0.875rem;font-style:italic;">No attachments</p>';
                        }
                        setBusy(sec, false);
                    })
                    .catch(function () {
                        sec.innerHTML = '<p style="color:var(--red);font-size:0.875rem;">Error loading attachments</p>';
                        setBusy(sec, false);
                    });
            };
            window.loadAttachments.__spSkeleton = true;
        }
    }

    /* Init */

    document.addEventListener('DOMContentLoaded', function () {
        initSidebar();
        initActiveNav();
        installDetailSkeletons();
    });
    // Also try immediately (defer runs before DOMContentLoaded; globals exist)
    installDetailSkeletons();
}());
