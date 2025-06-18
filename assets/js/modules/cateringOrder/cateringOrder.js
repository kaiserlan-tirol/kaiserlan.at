import $ from "jquery";

/**
 * CateringOrder Module
 * 
 * Handles the dynamic loading and display of catering products based on user selection.
 * Features:
 * - Dynamic product loading via AJAX
 * - Flatrate detection and pricing display
 * - Real-time total calculation
 * - Support for both standard select and Select2 widgets
 * 
 * Usage:
 * The module auto-initializes when the modal with ID 'createCateringOrderModal' is shown.
 * It can also be manually initialized: new CateringOrder(options);
 */

;
(function ($, window, document, undefined) {
    "use strict";
    
    let CateringOrder = function (options) {
        let defaults = {
            userSelectSelector: '#catering-user-select',
            productsContainerSelector: '#products-container',
            loadingIndicatorSelector: '#loading-products',
            productListSelector: '#product-list',
            totalPriceSelector: '#total-price',
            productQuantitySelector: '.product-quantity',
            productCardSelector: '.product-card',
            userProductsEndpoint: '/admin/catering/user-products/'
        };

        this.settings = $.extend({}, defaults, options);
        this._defaults = defaults;
        
        // Store product info to avoid multiple API calls
        this.userProducts = [];
        this.activeUserUuid = null;
        
        this.init();
    };
    
    $.extend(CateringOrder.prototype, {
        init() {
            this._findElements();
            this._setupEventListeners();
            this._updateTotal();
        },
        
        _findElements() {
            // Find the user select element - could have various IDs depending on form structure
            this.userSelect = document.getElementById('catering-user-select');
            
            // If not found by our expected ID, try to find it by form field name
            if (!this.userSelect) {
                this.userSelect = document.querySelector('select[name*="[user]"]');
            }
            
            // If still not found, try to find it by the select2 stimulus controller
            if (!this.userSelect) {
                this.userSelect = document.querySelector('select[data-controller*="select2"]');
            }
            
            this.productsContainer = document.querySelector(this.settings.productsContainerSelector);
            this.loadingIndicator = document.querySelector(this.settings.loadingIndicatorSelector);
            this.productList = document.querySelector(this.settings.productListSelector);
            this.totalPriceElement = document.querySelector(this.settings.totalPriceSelector);
        },
        
        _setupEventListeners() {
            if (!this.userSelect) {
                // User select element not found, nothing to do
                return;
            }
            
            // Check if jQuery is available and if the element has been initialized as select2
            const jQueryAvailable = (typeof $ !== 'undefined') || (typeof jQuery !== 'undefined');
            const jq = $ || jQuery;
            
            if (jQueryAvailable && jq(this.userSelect).hasClass('select2-hidden-accessible')) {
                // For select2, we need to listen to the select2:select event
                jq(this.userSelect).on('select2:select', (e) => {
                    const uuid = e.params.data.id;
                    if (uuid) {
                        this._loadUserProducts(uuid);
                    }
                });
                
                // Also listen to select2:clear for when selection is cleared
                jq(this.userSelect).on('select2:clear', (e) => {
                    this.activeUserUuid = null;
                    if (this.productsContainer) {
                        this.productsContainer.style.display = 'none';
                    }
                });
            } else {
                // Standard change event as fallback
                this.userSelect.addEventListener('change', () => {
                    const uuid = this.userSelect.value;
                    if (uuid && uuid !== '') {
                        this._loadUserProducts(uuid);
                    } else {
                        this.activeUserUuid = null;
                        if (this.productsContainer) {
                            this.productsContainer.style.display = 'none';
                        }
                    }
                });
            }
            
            // Set up quantity input listeners
            document.querySelectorAll(this.settings.productQuantitySelector).forEach(input => {
                input.addEventListener('input', () => this._updateTotal());
                
                // Add keyboard support
                input.addEventListener('keydown', (e) => {
                    if (e.key === 'ArrowUp') {
                        e.preventDefault();
                        this._incrementQuantity(input);
                    } else if (e.key === 'ArrowDown') {
                        e.preventDefault();
                        this._decrementQuantity(input);
                    }
                });
                
                // Validate input on blur
                input.addEventListener('blur', () => {
                    this._validateQuantityInput(input);
                });
            });
            
            // Set up increment/decrement button listeners
            this._setupQuantityButtons();
        },
        
        _incrementQuantity(input) {
            const currentValue = parseInt(input.value) || 0;
            const maxValue = parseInt(input.getAttribute('max')) || 99;
            
            if (currentValue < maxValue) {
                input.value = currentValue + 1;
                input.dispatchEvent(new Event('input', { bubbles: true }));
                this._updateQuantityButtonStates(input);
            }
        },
        
        _decrementQuantity(input) {
            const currentValue = parseInt(input.value) || 0;
            const minValue = parseInt(input.getAttribute('min')) || 0;
            
            if (currentValue > minValue) {
                input.value = currentValue - 1;
                input.dispatchEvent(new Event('input', { bubbles: true }));
                this._updateQuantityButtonStates(input);
            }
        },
        
        _validateQuantityInput(input) {
            const currentValue = parseInt(input.value) || 0;
            const minValue = parseInt(input.getAttribute('min')) || 0;
            const maxValue = parseInt(input.getAttribute('max')) || 99;
            
            // Ensure value is within bounds
            if (currentValue < minValue) {
                input.value = minValue;
            } else if (currentValue > maxValue) {
                input.value = maxValue;
            }
            
            // Trigger update
            input.dispatchEvent(new Event('input', { bubbles: true }));
            this._updateQuantityButtonStates(input);
        },
        
        _setupQuantityButtons() {
            // Handle increment buttons
            document.addEventListener('click', (e) => {
                if (e.target.closest('.quantity-increment')) {
                    const button = e.target.closest('.quantity-increment');
                    const targetId = button.dataset.target;
                    const input = document.getElementById(targetId);
                    
                    if (input) {
                        this._incrementQuantity(input);
                    }
                }
            });
            
            // Handle decrement buttons
            document.addEventListener('click', (e) => {
                if (e.target.closest('.quantity-decrement')) {
                    const button = e.target.closest('.quantity-decrement');
                    const targetId = button.dataset.target;
                    const input = document.getElementById(targetId);
                    
                    if (input) {
                        this._decrementQuantity(input);
                    }
                }
            });
        },
        
        _loadUserProducts(uuid) {
            if (!uuid || uuid === '') {
                if (this.productsContainer) {
                    this.productsContainer.style.display = 'none';
                }
                return;
            }
            
            // Special case for "Gast" - show products immediately with regular pricing
            if (uuid === 'guest') {
                this._showGuestProducts();
                return;
            }
            
            // Don't reload if user hasn't changed
            if (uuid === this.activeUserUuid) {
                if (this.productsContainer) {
                    this.productsContainer.style.display = 'block';
                }
                return;
            }
            
            this.activeUserUuid = uuid;
            
            if (this.productsContainer) {
                this.productsContainer.style.display = 'block';
            }
            if (this.productList) {
                this.productList.style.display = 'none';
            }
            if (this.loadingIndicator) {
                this.loadingIndicator.style.display = 'block';
            }
            
            const url = `${this.settings.userProductsEndpoint}${uuid}`;
            
            fetch(url)
                .then(response => {
                    if (!response.ok) {
                        throw new Error(`HTTP error! Status: ${response.status}`);
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        this.userProducts = data.products;
                        this._updateProductDisplay();
                    } else {
                        this._showError('Could not load user products');
                    }
                    this._hideLoadingIndicator();
                })
                .catch(() => {
                    this._showError('Failed to load products');
                    this._hideLoadingIndicator();
                });
        },
        
        _showGuestProducts() {
            // For guest users, show all products with regular pricing (no flatrate)
            this.activeUserUuid = 'guest';
            
            if (this.productsContainer) {
                this.productsContainer.style.display = 'block';
            }
            if (this.productList) {
                this.productList.style.display = 'none';
            }
            if (this.loadingIndicator) {
                this.loadingIndicator.style.display = 'block';
            }
            
            // Fetch all products with regular pricing for guest users
            const url = `${this.settings.userProductsEndpoint}guest`;
            
            fetch(url)
                .then(response => {
                    if (!response.ok) {
                        throw new Error(`HTTP error! Status: ${response.status}`);
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        this.userProducts = data.products;
                        this._updateProductDisplay();
                        
                        // Add a note that this is a guest order
                        const guestNote = document.createElement('div');
                        guestNote.className = 'alert alert-info mt-3';
                        guestNote.innerHTML = '<strong>Gast-Bestellung</strong>: Bitte beachten Sie, dass alle Produkte zum regulären Preis berechnet werden.';
                        
                        // Add to the top of product list
                        if (this.productList && this.productList.firstChild) {
                            this.productList.insertBefore(guestNote, this.productList.firstChild);
                        } else if (this.productList) {
                            this.productList.appendChild(guestNote);
                        }
                    } else {
                        this._showError('Could not load guest products');
                    }
                    this._hideLoadingIndicator();
                })
                .catch(() => {
                    this._showError('Failed to load products');
                    this._hideLoadingIndicator();
                });
        },
        
        _hideLoadingIndicator() {
            if (this.loadingIndicator) {
                this.loadingIndicator.style.display = 'none';
            }
            if (this.productList) {
                this.productList.style.display = 'block';
            }
        },
        
        _updateProductDisplay() {
            document.querySelectorAll(this.settings.productCardSelector).forEach(card => {
                const productId = parseInt(card.dataset.productId);
                const productInfo = this.userProducts.find(p => p.id === productId);
                
                if (productInfo) {
                    const priceElement = card.querySelector('.regular-price');
                    const flatrateBadge = card.querySelector('.flatrate-badge');
                    
                    if (productInfo.includedInFlatrate) {
                        // Product is included in flatrate
                        if (priceElement) priceElement.style.display = 'none';
                        if (flatrateBadge) flatrateBadge.style.display = 'inline-block';
                        card.classList.add('is-flatrate');
                    } else {
                        if (priceElement) priceElement.style.display = 'inline-block';
                        if (flatrateBadge) flatrateBadge.style.display = 'none';
                        card.classList.remove('is-flatrate');
                    }
                }
            });
            
            // Update total after updating product display
            this._updateTotal();
        },
        
        _updateTotal() {
            if (!this.totalPriceElement) {
                // Skip silently if price element isn't found
                return;
            }
            
            let total = 0;
            const quantityInputs = document.querySelectorAll(this.settings.productQuantitySelector);
            
            if (quantityInputs.length === 0) {
                // No inputs found, set total to 0
                this.totalPriceElement.textContent = (0).toLocaleString('de-DE', {
                    style: 'currency',
                    currency: 'EUR'
                });
                return;
            }
            
            quantityInputs.forEach(input => {
                const quantity = parseInt(input.value) || 0;
                
                // For flatrate products, price is 0
                let price = parseInt(input.dataset.price) || 0;
                const productCard = input.closest(this.settings.productCardSelector);
                
                if (!productCard) {
                    // Skip this input if there's no associated product card
                    return;
                }
                
                const productId = parseInt(productCard.dataset.productId);
                
                // Check if product is included in user's flatrate
                const productInfo = this.userProducts.find(p => p.id === productId);
                if (productInfo && productInfo.includedInFlatrate) {
                    price = 0; // Product is free in flatrate
                }
                
                total += quantity * price;
            });
            
            this.totalPriceElement.textContent = (total / 100).toLocaleString('de-DE', {
                style: 'currency',
                currency: 'EUR'
            });
            
            // Update button states for all quantity inputs
            quantityInputs.forEach(input => this._updateQuantityButtonStates(input));
        },
        
        _updateQuantityButtonStates(input) {
            const currentValue = parseInt(input.value) || 0;
            const minValue = parseInt(input.getAttribute('min')) || 0;
            const maxValue = parseInt(input.getAttribute('max')) || 99;
            
            // Find the associated buttons
            const container = input.closest('.quantity-input-group');
            if (!container) return;
            
            const decrementBtn = container.querySelector('.quantity-decrement');
            const incrementBtn = container.querySelector('.quantity-increment');
            
            // Update decrement button state
            if (decrementBtn) {
                if (currentValue <= minValue) {
                    decrementBtn.style.opacity = '0.5';
                    decrementBtn.style.cursor = 'not-allowed';
                } else {
                    decrementBtn.style.opacity = '1';
                    decrementBtn.style.cursor = 'pointer';
                }
            }
            
            // Update increment button state  
            if (incrementBtn) {
                if (currentValue >= maxValue) {
                    incrementBtn.style.opacity = '0.5';
                    incrementBtn.style.cursor = 'not-allowed';
                } else {
                    incrementBtn.style.opacity = '1';
                    incrementBtn.style.cursor = 'pointer';
                }
            }
        },
        
        /**
         * Display an error message to the user
         * @param {string} message - The error message to show
         * @private
         */
        _showError(message) {
            // Check if there's already an alert, remove it if so
            const existingAlert = document.querySelector('#catering-error-alert');
            if (existingAlert) {
                existingAlert.remove();
            }
            
            // Create a new alert
            const alertElement = document.createElement('div');
            alertElement.id = 'catering-error-alert';
            alertElement.className = 'alert alert-danger mt-3';
            alertElement.role = 'alert';
            alertElement.innerHTML = `
                <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
                ${message}
            `;
            
            // Find a good place to insert the alert
            if (this.productsContainer) {
                this.productsContainer.insertAdjacentElement('beforebegin', alertElement);
            } else {
                // Fallback to inserting it after the user select
                const userSelect = document.querySelector(this.settings.userSelectSelector);
                if (userSelect && userSelect.parentNode) {
                    userSelect.parentNode.insertAdjacentElement('afterend', alertElement);
                }
            }
            
            // Auto hide after 5 seconds
            setTimeout(() => {
                if (alertElement.parentNode) {
                    alertElement.remove();
                }
            }, 5000);
        }
    });
    
    // Module constants
    let MODULE_NAME = 'cateringOrder';
    let DATA_KEY = 'custom.' + MODULE_NAME;
    let EVENT_KEY = "." + DATA_KEY;
    let SELECTOR_DATA_TOGGLE = '[data-toggle="cateringOrder"]';
    
    // Auto-initialize when modal is shown
    $(document).on('shown.bs.modal', '#createCateringOrderModal', function() {
        setTimeout(() => {
            new CateringOrder();
            
            // Add handler for the manual submit button
            const manualSubmitBtn = document.getElementById('manual-submit-button');
            if (manualSubmitBtn) {
                manualSubmitBtn.addEventListener('click', function(e) {
                    const hiddenSubmitBtn = document.getElementById('hidden-form-submit');
                    
                    if (hiddenSubmitBtn) {
                        // This will trigger proper form validation and submission
                        hiddenSubmitBtn.click(); 
                    } else {
                        // Fallback to direct form submission
                        const form = document.getElementById('catering-order-form');
                        if (form) {
                            form.submit();
                        }
                    }
                });
            }
            
            // Add handler for the form itself
            const form = document.getElementById('catering-order-form');
            if (form) {
                form.addEventListener('submit', function(e) {
                    // Let the form submit naturally
                });
            }
        }, 100);
    });
    
    // Export for manual initialization if needed
    window.CateringOrder = CateringOrder;

})(jQuery, window, document);
