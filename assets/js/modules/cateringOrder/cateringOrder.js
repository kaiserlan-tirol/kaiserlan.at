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
            console.log('CateringOrder module initialized');
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
            
            console.log('Elements found:', {
                userSelect: !!this.userSelect,
                productsContainer: !!this.productsContainer,
                loadingIndicator: !!this.loadingIndicator,
                productList: !!this.productList,
                totalPriceElement: !!this.totalPriceElement
            });
        },
        
        _setupEventListeners() {
            if (!this.userSelect) {
                console.error('User select element not found!');
                return;
            }
            
            // Check if jQuery is available and if the element has been initialized as select2
            const jQueryAvailable = (typeof $ !== 'undefined') || (typeof jQuery !== 'undefined');
            const jq = $ || jQuery;
            
            console.log('jQuery available:', jQueryAvailable);
            
            if (jQueryAvailable && jq(this.userSelect).hasClass('select2-hidden-accessible')) {
                console.log('Using select2 events');
                
                // For select2, we need to listen to the select2:select event
                jq(this.userSelect).on('select2:select', (e) => {
                    console.log('Select2 selection event:', e);
                    const uuid = e.params.data.id;
                    console.log('Select2 selected UUID:', uuid);
                    if (uuid) {
                        this._loadUserProducts(uuid);
                    }
                });
                
                // Also listen to select2:clear for when selection is cleared
                jq(this.userSelect).on('select2:clear', (e) => {
                    console.log('Select2 cleared');
                    this.activeUserUuid = null;
                    if (this.productsContainer) {
                        this.productsContainer.style.display = 'none';
                    }
                });
            } else {
                console.log('Using standard change event');
                
                // Standard change event as fallback
                this.userSelect.addEventListener('change', () => {
                    console.log('Standard change event triggered!');
                    console.log('Selected option:', this.userSelect.selectedOptions[0]);
                    console.log('Value:', this.userSelect.value);
                    
                    const uuid = this.userSelect.value;
                    if (uuid && uuid !== '') {
                        console.log('Valid UUID found, loading products for:', uuid);
                        this._loadUserProducts(uuid);
                    } else {
                        console.log('No valid UUID available, value was:', uuid);
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
            console.log('_loadUserProducts called with UUID:', uuid);
            
            if (!uuid || uuid === '') {
                console.log('No UUID provided, hiding products container');
                if (this.productsContainer) {
                    this.productsContainer.style.display = 'none';
                }
                return;
            }
            
            // Don't reload if user hasn't changed
            if (uuid === this.activeUserUuid) {
                console.log('User UUID unchanged, showing products container without reload');
                if (this.productsContainer) {
                    this.productsContainer.style.display = 'block';
                }
                return;
            }
            
            this.activeUserUuid = uuid;
            console.log('Showing products container and loading indicator');
            
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
            console.log('Fetching from URL:', url);
            
            fetch(url)
                .then(response => {
                    console.log('Response status:', response.status);
                    if (!response.ok) {
                        throw new Error(`HTTP error! Status: ${response.status}`);
                    }
                    return response.json();
                })
                .then(data => {
                    console.log('Response data:', data);
                    if (data.success) {
                        this.userProducts = data.products;
                        console.log('Products loaded:', this.userProducts.length);
                        this._updateProductDisplay();
                    } else {
                        console.error('Error loading user products:', data.error);
                    }
                    this._hideLoadingIndicator();
                })
                .catch(error => {
                    console.error('Fetch error:', error);
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
                console.warn('Total price element not found, skipping total update');
                return;
            }
            
            let total = 0;
            const quantityInputs = document.querySelectorAll(this.settings.productQuantitySelector);
            
            if (quantityInputs.length === 0) {
                console.warn('No product quantity inputs found');
                return;
            }
            
            quantityInputs.forEach(input => {
                const quantity = parseInt(input.value) || 0;
                
                // For flatrate products, price is 0
                let price = parseInt(input.dataset.price) || 0;
                const productCard = input.closest(this.settings.productCardSelector);
                
                if (!productCard) {
                    console.warn('Product card not found for input:', input);
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
        }
    });
    
    // Module constants
    let MODULE_NAME = 'cateringOrder';
    let DATA_KEY = 'custom.' + MODULE_NAME;
    let EVENT_KEY = "." + DATA_KEY;
    let SELECTOR_DATA_TOGGLE = '[data-toggle="cateringOrder"]';
    
    // Auto-initialize when modal is shown
    $(document).on('shown.bs.modal', '#createCateringOrderModal', function() {
        console.log('Catering order modal shown, initializing...');
        // Small delay to ensure all elements are rendered
        setTimeout(() => {
            new CateringOrder();
        }, 100);
    });
    
    // Export for manual initialization if needed
    window.CateringOrder = CateringOrder;

})(jQuery, window, document);
