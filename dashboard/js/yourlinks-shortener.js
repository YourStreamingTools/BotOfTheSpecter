/**
 * YourLinks.click URL Shortener Integration
 * Provides functionality to detect URLs in input fields and create short links
 */

class YourLinksShortener {
    constructor() {
        this.urlPattern = /https?:\/\/[^\s]+/gi;
        this.detectedUrl = null;
        this.sourceFieldId = null;
    }
    initializeField(fieldId) {
        const field = document.getElementById(fieldId);
        if (!field) return;
        field.addEventListener('blur', () => {
            this.checkForUrl(fieldId);
        });
    }
    isYourLinksUrl(url) {
        // Check if URL matches https://yourlinks.click or https://*.yourlinks.click patterns
        const yourLinksPattern = /https?:\/\/([\w-]+\.)?yourlinks\.click/i;
        return yourLinksPattern.test(url);
    }
    checkForUrl(fieldId) {
        const field = document.getElementById(fieldId);
        if (!field) return;
        const content = field.value;
        const urls = content.match(this.urlPattern);
        if (urls && urls.length > 0) {
            this.detectedUrl = urls[0];
            this.sourceFieldId = fieldId;
            // Check if it's already a YourLinks.click URL
            if (this.isYourLinksUrl(this.detectedUrl)) {
                // Skip confirmation and show info that it's already shortened
                this.showAlreadyShortenedMessage();
                return;
            }
            this.showUrlDetectionConfirm();
        }
    }
    showUrlDetectionConfirm() {
        if (!this.detectedUrl) return;
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
        const modal = document.getElementById('yourlinksModal');
        if (!modal) {
            console.error('YourLinks modal not found');
            return;
        }
        // Pre-fill the destination URL
        const destinationInput = document.getElementById('yourlinks_destination');
        if (destinationInput) {
            destinationInput.value = this.detectedUrl;
        }
        // Clear other fields
        const linkNameInput = document.getElementById('yourlinks_link_name');
        const titleInput = document.getElementById('yourlinks_title');
        const statusDiv = document.getElementById('yourlinks_status');
        if (linkNameInput) linkNameInput.value = '';
        if (titleInput) titleInput.value = '';
        if (statusDiv) statusDiv.innerHTML = '';
        // Open modal
        modal.classList.add('is-active');
    }
    closeModal() {
        const modal = document.getElementById('yourlinksModal');
        if (modal) {
            modal.classList.remove('is-active');
        }
    }
    validateLinkName(name) {
        const linkNamePattern = /^[a-z0-9_-]+$/i;
        return linkNamePattern.test(name) && name.length > 0;
    }
    async createShortLink() {
        const linkNameInput = document.getElementById('yourlinks_link_name');
        const titleInput = document.getElementById('yourlinks_title');
        const destinationInput = document.getElementById('yourlinks_destination');
        const statusDiv = document.getElementById('yourlinks_status');
        const submitBtn = document.getElementById('yourlinks_submit_btn');
        if (!linkNameInput || !destinationInput) {
            this.showStatus('Missing required fields', 'danger', statusDiv);
            return;
        }
        const linkName = linkNameInput.value.trim();
        const title = titleInput ? titleInput.value.trim() : '';
        const destination = destinationInput.value.trim();
        // Validation
        if (!linkName) {
            this.showStatus('Link name is required', 'danger', statusDiv);
            linkNameInput.classList.add('is-danger');
            return;
        }
        if (!this.validateLinkName(linkName)) {
            this.showStatus('Link name can only contain alphanumeric characters, hyphens, and underscores', 'danger', statusDiv);
            linkNameInput.classList.add('is-danger');
            return;
        }
        if (!destination) {
            this.showStatus('Destination URL is required', 'danger', statusDiv);
            destinationInput.classList.add('is-danger');
            return;
        }
        // Show loading state
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="icon"><i class="fas fa-spinner fa-spin"></i></span><span>Creating...</span>';
        }
        try {
            // Build API request
            const params = new URLSearchParams({
                api: this.getApiKey(),
                link_name: linkName,
                destination: destination
            });
            if (title) {
                params.append('title', title);
            }
            const response = await fetch('https://yourlinks.click/services/api.php?' + params.toString());
            const data = await response.json();
            if (data.success) {
                this.showStatus(`Link created successfully!`, 'success', statusDiv);
                // Replace URL in source field
                this.replaceUrlInField(data.data.link_name);
                // Close modal after 2 seconds
                setTimeout(() => {
                    this.closeModal();
                }, 2000);
            } else {
                this.showStatus(`Error: ${data.message}`, 'danger', statusDiv);
            }
        } catch (error) {
            console.error('Error creating short link:', error);
            this.showStatus(`Error: ${error.message}`, 'danger', statusDiv);
        } finally {
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<span class="icon"><i class="fas fa-link"></i></span><span>Create Link</span>';
            }
        }
    }
    getApiKey() {
        const apiKeyElement = document.getElementById('yourlinks_api_key');
        if (apiKeyElement) {
            return apiKeyElement.value;
        }
        // Fallback: check if it's in a data attribute or meta tag
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
        // Get username from the API key or data attribute
        const username = this.getUsername();
        const shortUrl = `https://${username}.yourlinks.click/${linkName}`;
        field.value = field.value.replace(this.detectedUrl, shortUrl);
    }
    getUsername() {
        const usernameElement = document.getElementById('yourlinks_username');
        if (usernameElement) {
            return usernameElement.value;
        }
        // Fallback: check if it's in a data attribute
        const metaTag = document.querySelector('meta[name="twitch-username"]');
        if (metaTag) {
            return metaTag.getAttribute('content');
        }
        return 'user';
    }
    showStatus(message, type = 'info', container = null) {
        if (!container) {
            container = document.getElementById('yourlinks_status');
        }
        if (!container) return;
        const notificationClass = `notification is-${type} is-light`;
        const iconClass = this.getIconClass(type);
        container.innerHTML = `
            <div class="${notificationClass}">
                <span class="icon"><i class="${iconClass}"></i></span>
                <span>${message}</span>
            </div>
        `;
    }
    getIconClass(type) {
        const icons = {
            'success': 'fas fa-check-circle',
            'danger': 'fas fa-exclamation-circle',
            'warning': 'fas fa-exclamation-triangle',
            'info': 'fas fa-info-circle'
        };
        return icons[type] || icons['info'];
    }
    clearErrors() {
        const inputs = [
            document.getElementById('yourlinks_link_name'),
            document.getElementById('yourlinks_destination'),
            document.getElementById('yourlinks_title')
        ];
        inputs.forEach(input => {
            if (input) {
                input.classList.remove('is-danger');
            }
        });
    }
    setupModalHandling() {
        const modal = document.getElementById('yourlinksModal');
        const closeBtn = document.getElementById('yourlinks_close_btn');
        const cancelBtn = document.getElementById('yourlinks_cancel_btn');
        if (closeBtn) {
            closeBtn.addEventListener('click', () => this.closeModal());
        }
        if (cancelBtn) {
            cancelBtn.addEventListener('click', () => this.closeModal());
        }
        if (modal) {
            modal.addEventListener('click', (e) => {
                if (e.target === modal) {
                    this.closeModal();
                }
            });
        }
    }
    setupSubmitButton() {
        const submitBtn = document.getElementById('yourlinks_submit_btn');
        if (submitBtn) {
            submitBtn.addEventListener('click', () => this.createShortLink());
        }
    }
    setupLinkNameValidation() {
        const linkNameInput = document.getElementById('yourlinks_link_name');
        if (!linkNameInput) return;
        linkNameInput.addEventListener('input', () => {
            if (linkNameInput.value && !this.validateLinkName(linkNameInput.value)) {
                linkNameInput.classList.add('is-danger');
            } else {
                linkNameInput.classList.remove('is-danger');
            }
        });
    }
    initialize() {
        this.setupModalHandling();
        this.setupSubmitButton();
        this.setupLinkNameValidation();
    }
}

// Create global instance
const yourLinksShortener = new YourLinksShortener();

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    yourLinksShortener.initialize();
});
