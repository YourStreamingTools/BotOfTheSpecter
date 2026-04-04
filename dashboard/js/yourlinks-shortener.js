/**
 * YourLinks.click URL Shortener Integration
 * Provides functionality to detect URLs in input fields and create short links
 */

class YourLinksShortener {
    constructor() {
        this.urlPattern = /https?:\/\/[^\s]+/gi;
        this.detectedUrl = null;
        this.sourceFieldId = null;
        this.suppressPromptsAfterDecline = false;
        this.promptDeclinedThisPage = false;
    }
    setSuppressPromptsAfterDecline(enabled) {
        this.suppressPromptsAfterDecline = !!enabled;
    }
    initializeField(fieldId) {
        const field = document.getElementById(fieldId);
        if (!field) return;
        field.addEventListener('blur', () => {
            this.checkForUrl(fieldId);
        });
    }
    isYourLinksUrl(url) {
        const yourLinksPattern = /https?:\/\/([\w-]+\.)?yourlinks\.click/i;
        return yourLinksPattern.test(url);
    }
    isCustomVariable(url) {
        return /^\(customapi\./i.test(url);
    }
    checkForUrl(fieldId) {
        const field = document.getElementById(fieldId);
        if (!field) return;
        const content = field.value;
        const urls = content.match(this.urlPattern);
        if (urls && urls.length > 0) {
            this.detectedUrl = urls[0];
            this.sourceFieldId = fieldId;
            if (this.isYourLinksUrl(this.detectedUrl)) {
                return;
            }
            if (this.isCustomVariable(this.detectedUrl)) {
                return;
            }
            this.showUrlDetectionConfirm();
        }
    }
    showUrlDetectionConfirm() {
        if (!this.detectedUrl) return;
        if (this.suppressPromptsAfterDecline && this.promptDeclinedThisPage) {
            return;
        }
        Swal.fire({
            title: 'URL Detected',
            html: `We detected a URL in your message: <br><code style="word-break: break-all; font-size: 12px;">${this.detectedUrl}</code><br><br>Would you like to create a short link using YourLinks.click?`,
            icon: 'info',
            showCancelButton: true,
            confirmButtonColor: '#3085d6',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Yes, shorten it!',
            cancelButtonText: 'No, keep original'
        }).then((result) => {
            if (result.isConfirmed) {
                this.openShorteningModal();
                return;
            }
            if (
                this.suppressPromptsAfterDecline &&
                result.dismiss === Swal.DismissReason.cancel
            ) {
                this.promptDeclinedThisPage = true;
            }
        });
    }
    showAlreadyShortenedMessage() {
        if (!this.detectedUrl) return;
        Swal.fire({
            title: 'Link Already Shortened',
            html: `This URL is already a YourLinks.click shortened link: <br><code style="word-break: break-all; font-size: 12px;">${this.detectedUrl}</code><br><br>No need to shorten it again!`,
            icon: 'info',
            confirmButtonColor: '#3085d6',
            confirmButtonText: 'Got it!'
        });
    }
    openShorteningModal() {
        const username = this.getUsername();
        Swal.fire({
            title: 'Create Short Link',
            html:
                `<div style="text-align:left;">` +
                    `<label style="display:block; font-size:0.82rem; font-weight:600; color:#a8a8bc; margin-bottom:0.35rem; text-transform:uppercase; letter-spacing:0.05em;">Destination URL</label>` +
                    `<input id="swal_yourlinks_destination" class="swal2-input" type="url" value="${this.escapeHtml(this.detectedUrl)}" readonly style="background:#1a1a20; color:#a8a8bc; cursor:not-allowed; margin:0 0 0.5rem 0; width:100%; box-sizing:border-box;">` +
                    `<small style="display:block; color:#6c6c84; font-size:0.78rem; margin-bottom:1rem;">The URL you entered in the message</small>` +
                    `<label style="display:block; font-size:0.82rem; font-weight:600; color:#a8a8bc; margin-bottom:0.35rem; text-transform:uppercase; letter-spacing:0.05em;">Link Name <span style="color:#f87171;">*</span></label>` +
                    `<input id="swal_yourlinks_link_name" class="swal2-input" type="text" placeholder="e.g., discord, youtube, twitch" maxlength="50" autocomplete="off" style="margin:0 0 0.5rem 0; width:100%; box-sizing:border-box;">` +
                    `<small id="swal_yourlinks_link_preview" style="display:block; color:#6c6c84; font-size:0.78rem; margin-bottom:1rem;">Alphanumeric characters, hyphens, and underscores only. Will be: <code>${username}.yourlinks.click/<strong>linkname</strong></code></small>` +
                    `<label style="display:block; font-size:0.82rem; font-weight:600; color:#a8a8bc; margin-bottom:0.35rem; text-transform:uppercase; letter-spacing:0.05em;">Title (Optional)</label>` +
                    `<input id="swal_yourlinks_title" class="swal2-input" type="text" placeholder="e.g., Join My Discord Server" maxlength="100" autocomplete="off" style="margin:0 0 0.5rem 0; width:100%; box-sizing:border-box;">` +
                    `<small style="display:block; color:#6c6c84; font-size:0.78rem;">Display name for the link (for your reference)</small>` +
                `</div>`,
            focusConfirm: false,
            showCancelButton: true,
            confirmButtonText: '<i class="fas fa-link"></i> Create Link',
            cancelButtonText: '<i class="fas fa-times"></i> Cancel',
            confirmButtonColor: '#7c5cbf',
            cancelButtonColor: '#6c757d',
            width: '620px',
            didOpen: () => {
                const linkNameInput = document.getElementById('swal_yourlinks_link_name');
                if (linkNameInput) {
                    linkNameInput.focus();
                    linkNameInput.addEventListener('input', () => {
                        this.updateSwalLinkPreview(linkNameInput.value, username);
                    });
                }
            },
            preConfirm: () => {
                const linkName = document.getElementById('swal_yourlinks_link_name').value.trim();
                const title = document.getElementById('swal_yourlinks_title').value.trim();
                const destination = document.getElementById('swal_yourlinks_destination').value.trim();
                if (!linkName) {
                    Swal.showValidationMessage('Link name is required');
                    return false;
                }
                if (!this.validateLinkName(linkName)) {
                    Swal.showValidationMessage('Link name can only contain alphanumeric characters, hyphens, and underscores');
                    return false;
                }
                return { linkName, title, destination };
            }
        }).then((result) => {
            if (result.isConfirmed) {
                this.submitShortLink(result.value.linkName, result.value.title, result.value.destination);
            }
        });
    }
    updateSwalLinkPreview(linkName, username) {
        const preview = document.getElementById('swal_yourlinks_link_preview');
        if (!preview) return;
        const display = linkName || 'linkname';
        preview.innerHTML = `Alphanumeric characters, hyphens, and underscores only. Will be: <code>${username}.yourlinks.click/<strong>${this.escapeHtml(display)}</strong></code>`;
    }
    escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }
    validateLinkName(name) {
        const linkNamePattern = /^[a-z0-9_-]+$/i;
        return linkNamePattern.test(name) && name.length > 0;
    }
    async submitShortLink(linkName, title, destination) {
        Swal.fire({
            title: 'Creating Link...',
            allowOutsideClick: false,
            didOpen: () => { Swal.showLoading(); }
        });
        try {
            const requestData = {
                api: this.getApiKey(),
                link_name: linkName,
                destination: destination
            };
            if (title) {
                requestData.title = title;
            }
            const response = await fetch('/api/yourlinks_create.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(requestData)
            });
            const data = await response.json();
            if (data.success) {
                this.replaceUrlInField(data.data.link_name);
                Swal.fire({
                    title: 'Link Created!',
                    icon: 'success',
                    confirmButtonColor: '#7c5cbf',
                    timer: 2000,
                    timerProgressBar: true
                });
            } else {
                Swal.fire({
                    title: 'Error',
                    text: data.message,
                    icon: 'error',
                    confirmButtonColor: '#7c5cbf'
                });
            }
        } catch (error) {
            console.error('Error creating short link:', error);
            Swal.fire({
                title: 'Error',
                text: error.message,
                icon: 'error',
                confirmButtonColor: '#7c5cbf'
            });
        }
    }
    getApiKey() {
        const apiKeyElement = document.getElementById('yourlinks_api_key');
        if (apiKeyElement) {
            return apiKeyElement.value;
        }
        const metaTag = document.querySelector('meta[name="yourlinks-api-key"]');
        if (metaTag) {
            return metaTag.getAttribute('content');
        }
        return '';
    }
    replaceUrlInField(linkName) {
        if (!this.sourceFieldId) return;
        const field = document.getElementById(this.sourceFieldId);
        if (!field) return;
        const username = this.getUsername();
        const shortUrl = `https://${username}.yourlinks.click/${linkName}`;
        const escapedUrl = this.detectedUrl.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        const regex = new RegExp(escapedUrl, 'gi');
        const newValue = field.value.replace(regex, shortUrl);
        if (newValue !== field.value) {
            field.value = newValue;
            const inputEvent = new Event('input', { bubbles: true });
            const changeEvent = new Event('change', { bubbles: true });
            field.dispatchEvent(inputEvent);
            field.dispatchEvent(changeEvent);
        }
    }
    getUsername() {
        const usernameElement = document.getElementById('yourlinks_username');
        if (usernameElement && usernameElement.value) {
            return usernameElement.value;
        }
        const metaTag = document.querySelector('meta[name="twitch-username"]');
        if (metaTag) {
            return metaTag.getAttribute('content');
        }
        return 'user';
    }
    initialize() {
        // No custom modal setup needed - everything uses SweetAlert2
    }
}

// Create global instance
const yourLinksShortener = new YourLinksShortener();

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    yourLinksShortener.initialize();
});
