import $ from 'jquery';

const Shop = function ($root, config) {
    this.$root = $root;

    this.$tickets = this.$root.find('#ticketWrapper');
    this.$buttonsWrapper = this.$tickets.find('#chooseTicketWrapper');
    this.$buttons = this.$buttonsWrapper.find('[data-mode]');
    this.$buttons.on('click', (e) => this.smNext(e.currentTarget.dataset['mode']));

    this.$paneOne = this.$tickets.find('#ticketSelfBody');
    this.$paneMore = this.$tickets.find('#ticketClanBody');
    this.$paneRedeem = this.$tickets.find('#redeemCodeBody');
    this.$backButton = this.$tickets.find('#backButton');

    this.$paneAdditional = this.$root.find('#ticketAdditionalBody');
    this.$showAdditionalPane = this.$root.find('#showAdditionalTicketsPane');
    this.$showAdditionalPane.find('#showAdditionalTickets').on('click', (e) => { e.preventDefault(); this._showAdditional(); });

    this.$form = this.$root.find('form');
    this.$formTicketCount = this.$paneMore.find('input');
    this.$formRedeemInput = this.$paneRedeem.find('input');
    this.$formRedeemButton = this.$paneRedeem.find('button');
    this.$formRedeemButton.on('click', () => this._redeem());
    this.$formTicketAdditional = this.$paneAdditional.find('input');
    this.$formTicketAdditional.val(0);

    this.$addons = this.$root.find('#addonWrapper');
    this.$addonInputs = this.$addons.find('input');
    this.$buttonReset = this.$root.find('button[type="reset"]');
    this.$buttonReset.on('click', (e) => { e.preventDefault(); this.smClear() });

    // Per-ticket addon system elements
    this.$ticketAddonList = this.$root.find('#ticket-addon-list');
    this.$noTicketsMessage = this.$ticketAddonList.find('#no-tickets-message');
    this.$ticketAddonSections = this.$ticketAddonList.find('.ticket-addon-section');

    this.$submit = this.$root.find('#submitWrapper');

    this.visibilityStates = storeVisibility([
        this.$buttonsWrapper,
        this.$backButton,
        this.$paneAdditional,
        this.$paneRedeem,
        this.$paneOne,
        this.$paneMore,
        this.$addons,
        this.$submit,
    ]);

    this.defaultValues = storeValues([
        this.$formRedeemInput,
        this.$formTicketCount,
        this.$formTicketAdditional,
    ].concat(this.$addonInputs.map((i, v) => $(v)).get()));

    this.path = config['path'];
    this.smClear();

    // Set up ticket count change listener for per-ticket addons
    this.$formTicketCount.on('input change', () => this._updateTicketAddonVisibility());
    
    // Also listen for additional tickets
    this.$formTicketAdditional.on('input change', () => this._updateTicketAddonVisibility());

    // go straight to add-on in case ticket is not rendered
    if (this.$buttonsWrapper.length === 0) {
        this.smNext('addon');
    }
    
    // Initialize number input controls
    this._initNumberInputControls();
}

function storeVisibility(elements) {
    return elements.map(e => { return { element: e, hidden: e.hasClass('d-none') }; })
}

function restoreVisibility(states) {
    for (const state of states) {
        if (state.hidden) { state.element.addClass('d-none'); } else { state.element.removeClass('d-none'); }
    }
}

function storeValues(elements) {
    return elements.map(e => { return { element: e, value: e.val(), checked: e.prop('checked') }; });
}

function restoreValues(states) {
    for (const s of states) {
        s.element.val(s.value);
        s.element.prop('checked', s.checked);
    }
}

const States = Object.freeze({
    START:   Symbol("start"),
    REDEEM:  Symbol("redeem"),
    BUY:     Symbol("buy"),
});

$.extend(Shop.prototype, {
    smClear() {
        this.state = States.START;
        restoreVisibility(this.visibilityStates);
        restoreValues(this.defaultValues);
        this._redeemShowState();
    },
    smNext(mode) {
        let new_state = this.state;
        switch (this.state) {
            case States.START:
                switch (mode) {
                    case 'one':
                    case 'multi':
                    case 'addon':
                        new_state = States.BUY;
                        break;
                    case 'redeem':
                        new_state = States.REDEEM;
                        break;
                }
                break;
            case States.REDEEM:
                new_state = States.BUY;
                break;
            case States.BUY:
                break;
        }
        if (new_state !== this.state) {
            this.updateUi(mode);
            this.state = new_state;
        }
    },
    updateUi(mode) {
        switch (this.state) {
            case States.START:
                switch (mode) {
                    case 'one':
                        this._showSingle();
                        this._showAddon();
                        break;
                    case 'multi':
                        this._showMany();
                        this._showAddon();
                        break;
                    case 'addon':
                        this._showAddon();
                        break;
                    case 'redeem':
                        this._showRedeem();
                        break;
                }
                break;
            case States.REDEEM:
                this._showAddon();
                break;
            case States.BUY:
                break;
        }
    },
    _showSingle() {
        this.$formTicketCount.val(1);
        this.$buttonsWrapper.addClass('d-none');
        this.$paneOne.removeClass('d-none');
        // Trigger addon visibility update for per-ticket system
        this._updateTicketAddonVisibility();
    },
    _showMany() {
        this.$formTicketCount.val(1);
        //this.$formTicketCount.min(1);
        this.$buttonsWrapper.addClass('d-none');
        this.$paneMore.removeClass('d-none');
        // Trigger addon visibility update for per-ticket system
        this._updateTicketAddonVisibility();
    },
    _showRedeem() {
        this.$formTicketCount.val(0);
        this.$formRedeemInput.val('');
        this.$buttonsWrapper.addClass('d-none');
        this.$paneRedeem.removeClass('d-none');
        this.$backButton.removeClass('d-none');
    },
    _showAddon() {
        this.$addons.find('input').val(0);
        this.$backButton.addClass('d-none');
        this.$addons.removeClass('d-none');
        this.$submit.removeClass('d-none');
        
        // Update per-ticket addon visibility
        this._updateTicketAddonVisibility();
    },
    _updateTicketAddonVisibility() {
        const ticketCount = parseInt(this.$formTicketCount.val() || 0);
        const additionalTickets = parseInt(this.$formTicketAdditional.val() || 0);
        const totalTickets = ticketCount + additionalTickets;
        
        if (this.$ticketAddonSections.length > 0) {
            // Per-ticket addon system is active
            if (totalTickets > 0) {
                this.$noTicketsMessage.hide();
                
                // Show addon sections for the selected number of tickets
                this.$ticketAddonSections.each((index, element) => {
                    const $section = $(element);
                    if (index < totalTickets) {
                        $section.show();
                    } else {
                        $section.hide();
                        // Reset form values for hidden sections
                        $section.find('input').val(0).prop('checked', false);
                    }
                });
            } else {
                this.$noTicketsMessage.show();
                this.$ticketAddonSections.hide();
                // Reset all addon form values
                this.$ticketAddonSections.find('input').val(0).prop('checked', false);
            }
        }
    },
    _showAdditional() {
        this.$formTicketAdditional.val(0);
        this.$showAdditionalPane.addClass('d-none');
        this.$paneAdditional.removeClass('d-none');
    },
    _checkCode(code) {
        return new Promise(((resolve, reject) => {
            $.ajax({
                url: this.path,
                method: 'GET',
                data: {
                    'code': code,
                },
            }).then((data, textStatus, jqXHR) => {
                if (data.result === true) { resolve(data); }
                else { reject(); }
            }).catch((jqXHR) => {
                reject();
            });
        }));
    },
    _redeem() {
        const elem = this.$formRedeemInput;
        const code = elem.val()
        const pattern = elem.attr("pattern");
        const re = new RegExp(pattern);
        if (re.test(code)) {
            this._checkCode(code)
                .then(r => { this._redeemShowState(true); this.smNext(); })
                .catch(r => { this._redeemShowState(false); });
        } else {
            this._redeemShowState(false);
        }
    },
    _redeemShowState(ok) {
        if (ok === true) {
            this.$formRedeemButton.prop('disabled', true);
            this.$formRedeemInput.prop('readonly', true).removeClass('is-invalid').addClass('is-valid');
        } else if (ok === false) {
            this.$formRedeemInput.prop('readonly', false).removeClass('is-valid').addClass('is-invalid');
        } else {
            this.$formRedeemButton.prop('disabled', false);
            this.$formRedeemInput.prop('readonly', false).removeClass('is-invalid').removeClass('is-valid');
        }
    },
    
    // Initialize custom number input controls with +/- buttons
    _initNumberInputControls() {
        // Handle increment buttons
        $(document).on('click', '.number-increment, .number-input-btn.number-increment', (e) => {
            e.preventDefault();
            const $button = $(e.currentTarget);
            const $wrapper = $button.closest('.number-input-wrapper');
            const $input = $wrapper.find('input[type="number"]');
            
            if ($input.length) {
                const currentVal = parseInt($input.val()) || 0;
                const max = parseInt($input.attr('max')) || 999;
                
                if (currentVal < max) {
                    $input.val(currentVal + 1).trigger('change');
                }
            }
        });

        // Handle decrement buttons
        $(document).on('click', '.number-decrement, .number-input-btn.number-decrement', (e) => {
            e.preventDefault();
            const $button = $(e.currentTarget);
            const $wrapper = $button.closest('.number-input-wrapper');
            const $input = $wrapper.find('input[type="number"]');
            
            if ($input.length) {
                const currentVal = parseInt($input.val()) || 0;
                const min = parseInt($input.attr('min')) || 0;
                
                if (currentVal > min) {
                    $input.val(currentVal - 1).trigger('change');
                }
            }
        });

        // Allow keyboard input on number fields (remove readonly when focused)
        $(document).on('focus', '.number-input', (e) => {
            $(e.currentTarget).removeAttr('readonly');
        });

        // Add readonly back when focus is lost and validate input
        $(document).on('blur', '.number-input', (e) => {
            const $input = $(e.currentTarget);
            const currentVal = parseInt($input.val()) || 0;
            const min = parseInt($input.attr('min')) || 0;
            const max = parseInt($input.attr('max')) || 999;
            
            // Clamp value to min/max bounds
            let clampedVal = Math.max(min, Math.min(max, currentVal));
            
            $input.val(clampedVal).attr('readonly', true).trigger('change');
        });
    },
});

$(document).ready(() => {
    const shop = new Shop($('#shop'),{
        path: '/shop/check',
    });
});