/**
 * Campaign Page JavaScript
 * Handles campaign-specific donation flow with matching display and PayArc/Stripe support
 */

document.addEventListener('DOMContentLoaded', () => {
    // State
    let selectedAmount = 100;
    let frequency = 'once';
    let stripe = null;
    let elements = null;
    let paymentElement = null;
    let clientSecret = null;
    let donationId = null;
    let paymentMode = 'payment'; // 'payment' or 'subscription'

    // Campaign-specific config
    const basePath = CONFIG.basePath || '';
    const matchingEnabled = CONFIG.matchingEnabled || false;
    const matchingMultiplier = CONFIG.matchingMultiplier || 1;
    const campaignId = CONFIG.campaignId || null;
    const currencySymbol = CONFIG.currencySymbol || '$';

    // Elements
    const amountBtns = document.querySelectorAll('.amount-btn');
    const freqBtns = document.querySelectorAll('.freq-btn');
    const customInput = document.getElementById('custom-amount');
    const stripeBtn = document.getElementById('stripe-btn');
    const amountStep = document.getElementById('amount-step');
    const paymentStep = document.getElementById('payment-step');
    const amountSelectionContainer = document.getElementById('amount-selection-container');
    const backBtn = document.getElementById('back-btn');
    const submitBtn = document.getElementById('submit-payment');
    const paymentMessage = document.getElementById('payment-message');
    const donorName = document.getElementById('donor-name');
    const donorEmail = document.getElementById('donor-email');
    const displayName = document.getElementById('display-name');
    const donationMessageInput = document.getElementById('donation-message');
    const isAnonymous = document.getElementById('is-anonymous');

    // PayArc card inputs
    const cardNumberInput = document.getElementById('card-number');
    const cardExpiryInput = document.getElementById('card-expiry');
    const cardCvvInput = document.getElementById('card-cvv');

    // Matching display elements
    const donationAmountEl = document.getElementById('donation-amount');
    const matchedAmountEl = document.getElementById('matched-amount');

    // Always initialize Stripe if key exists (needed for Apple Pay/Google Pay even when PayArc handles cards)
    if (CONFIG.stripeKey) {
        stripe = Stripe(CONFIG.stripeKey);
    }

    // Card number formatting
    if (cardNumberInput) {
        cardNumberInput.addEventListener('input', (e) => {
            let value = e.target.value.replace(/\D/g, '');
            value = value.replace(/(.{4})/g, '$1 ').trim();
            e.target.value = value.substring(0, 19);
        });
    }

    // Expiry formatting (MM/YY)
    if (cardExpiryInput) {
        cardExpiryInput.addEventListener('input', (e) => {
            let value = e.target.value.replace(/\D/g, '');
            if (value.length >= 2) {
                value = value.substring(0, 2) + '/' + value.substring(2, 4);
            }
            e.target.value = value;
        });
    }

    // CVV formatting
    if (cardCvvInput) {
        cardCvvInput.addEventListener('input', (e) => {
            e.target.value = e.target.value.replace(/\D/g, '').substring(0, 4);
        });
    }

    // Update matching display
    function updateMatchingDisplay() {
        if (!matchingEnabled) return;

        if (donationAmountEl) {
            donationAmountEl.textContent = currencySymbol + selectedAmount.toLocaleString();
        }
        if (matchedAmountEl) {
            const matchedAmount = selectedAmount * matchingMultiplier;
            matchedAmountEl.textContent = currencySymbol + matchedAmount.toLocaleString();
        }
    }

    // Amount button click
    amountBtns.forEach(btn => {
        btn.addEventListener('click', () => {
            amountBtns.forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            selectedAmount = parseInt(btn.dataset.amount);
            if (customInput) customInput.value = '';
            updateMatchingDisplay();
        });
    });

    // Custom amount input
    if (customInput) {
        customInput.addEventListener('input', (e) => {
            const val = parseInt(e.target.value);
            if (val > 0) {
                amountBtns.forEach(b => b.classList.remove('active'));
                selectedAmount = val;
                updateMatchingDisplay();
            }
        });

        customInput.addEventListener('focus', () => {
            amountBtns.forEach(b => b.classList.remove('active'));
        });
    }

    // Frequency toggle
    freqBtns.forEach(btn => {
        btn.addEventListener('click', () => {
            freqBtns.forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            frequency = btn.dataset.freq;
        });
    });

    // Show payment step
    async function showPaymentStep() {
        if (selectedAmount < 1) {
            alert('Please select or enter a valid amount');
            return;
        }

        stripeBtn.disabled = true;
        stripeBtn.textContent = 'Loading...';

        try {
            if (CONFIG.payarcEnabled) {
                // PayArc: Just show the payment form (no API call needed yet)
                amountStep.style.display = 'none';
                if (amountSelectionContainer) amountSelectionContainer.style.display = 'none';
                paymentStep.style.display = 'block';

                // Update button text for monthly
                if (frequency === 'monthly') {
                    document.getElementById('button-text').textContent =
                        `Start ${currencySymbol}${selectedAmount}/month Donation`;
                } else {
                    document.getElementById('button-text').textContent = 'Complete Donation';
                }

                // Initialize Express Checkout for Apple Pay/Google Pay if Stripe is available
                const expressCheckoutContainer = document.getElementById('express-checkout-element');
                if (stripe && expressCheckoutContainer) {
                    // Show placeholder until name/email are filled
                    expressCheckoutContainer.innerHTML = '<div id="express-checkout-placeholder" style="text-align: center; padding: 12px; background: #f5f5f5; border-radius: 8px; color: #666; font-size: 13px;">Fill in your name and email above to enable Apple Pay / Google Pay</div>';

                    let expressCheckoutMounted = false;
                    let expressElements = null;
                    let expressCheckoutElement = null;
                    let currentIntentData = null;

                    // Function to check if we can mount Express Checkout
                    const checkAndMountExpressCheckout = async () => {
                        const name = donorName ? donorName.value.trim() : '';
                        const email = donorEmail ? donorEmail.value.trim() : '';

                        if (name && email && isValidEmail(email) && !expressCheckoutMounted) {
                            // Remove placeholder
                            const placeholder = document.getElementById('express-checkout-placeholder');
                            if (placeholder) placeholder.remove();

                            try {
                                // Create a PaymentIntent with donor details
                                const intentResponse = await fetch(basePath + '/api/create-payment-intent.php', {
                                    method: 'POST',
                                    headers: { 'Content-Type': 'application/json' },
                                    body: JSON.stringify({
                                        amount: selectedAmount,
                                        frequency: frequency,
                                        campaign_id: campaignId,
                                        donor_name: name,
                                        donor_email: email,
                                        display_name: displayName ? displayName.value.trim() : '',
                                        donation_message: donationMessageInput ? donationMessageInput.value.trim() : '',
                                        is_anonymous: isAnonymous ? isAnonymous.checked : false,
                                        csrf_token: CONFIG.csrfToken
                                    })
                                });
                                currentIntentData = await intentResponse.json();

                                if (currentIntentData.clientSecret) {
                                    // Mount Express Checkout Element
                                    expressElements = stripe.elements({
                                        clientSecret: currentIntentData.clientSecret,
                                        appearance: { theme: 'stripe' }
                                    });
                                    expressCheckoutElement = expressElements.create('expressCheckout');
                                    expressCheckoutElement.mount('#express-checkout-element');
                                    expressCheckoutMounted = true;

                                    // Handle express checkout confirmation
                                    expressCheckoutElement.on('confirm', async (event) => {
                                        // Update donation record with latest donor details before confirming
                                        try {
                                            await fetch(basePath + '/api/update-donation.php', {
                                                method: 'POST',
                                                headers: { 'Content-Type': 'application/json' },
                                                body: JSON.stringify({
                                                    donation_id: currentIntentData.donationId,
                                                    donor_name: donorName ? donorName.value.trim() : '',
                                                    donor_email: donorEmail ? donorEmail.value.trim() : '',
                                                    display_name: displayName ? displayName.value.trim() : '',
                                                    donation_message: donationMessageInput ? donationMessageInput.value.trim() : '',
                                                    is_anonymous: isAnonymous ? isAnonymous.checked : false,
                                                    csrf_token: CONFIG.csrfToken
                                                })
                                            });
                                        } catch (updateError) {
                                            console.log('Could not update donor details:', updateError.message);
                                        }

                                        // Different confirmation for subscriptions vs one-time payments
                                        if (currentIntentData.mode === 'subscription') {
                                            // For subscriptions, use confirmSetup then call server to create subscription
                                            const { error, setupIntent } = await stripe.confirmSetup({
                                                elements: expressElements,
                                                clientSecret: currentIntentData.clientSecret,
                                                confirmParams: {
                                                    return_url: window.location.origin + basePath + '/success.php'
                                                },
                                                redirect: 'if_required'
                                            });

                                            if (error) {
                                                showMessage(error.message, 'error');
                                            } else if (setupIntent && setupIntent.status === 'succeeded') {
                                                // SetupIntent confirmed - now create subscription server-side
                                                try {
                                                    const confirmResponse = await fetch(basePath + '/api/confirm-payment.php', {
                                                        method: 'POST',
                                                        headers: { 'Content-Type': 'application/json' },
                                                        body: JSON.stringify({
                                                            mode: 'subscription',
                                                            intent_id: setupIntent.id,
                                                            amount: currentIntentData.amount || selectedAmount,
                                                            donor_name: donorName ? donorName.value.trim() : '',
                                                            donor_email: donorEmail ? donorEmail.value.trim() : '',
                                                            display_name: displayName ? displayName.value.trim() : '',
                                                            donation_message: donationMessageInput ? donationMessageInput.value.trim() : '',
                                                            is_anonymous: isAnonymous ? isAnonymous.checked : false,
                                                            csrf_token: CONFIG.csrfToken
                                                        })
                                                    });
                                                    const confirmData = await confirmResponse.json();

                                                    if (confirmData.success) {
                                                        const successUrl = basePath + '/success.php?id=' + confirmData.donationId +
                                                            (campaignId ? '&campaign=' + campaignId : '');
                                                        window.location.href = successUrl;
                                                    } else {
                                                        showMessage(confirmData.error || 'Subscription creation failed', 'error');
                                                    }
                                                } catch (confirmError) {
                                                    showMessage('Subscription error: ' + confirmError.message, 'error');
                                                }
                                            }
                                        } else {
                                            // For one-time payments, use confirmPayment
                                            const { error } = await stripe.confirmPayment({
                                                elements: expressElements,
                                                clientSecret: currentIntentData.clientSecret,
                                                confirmParams: {
                                                    return_url: window.location.origin + basePath + '/success.php'
                                                }
                                            });
                                            if (error) {
                                                showMessage(error.message, 'error');
                                            }
                                        }
                                    });
                                }
                            } catch (expressError) {
                                console.log('Express checkout not available:', expressError.message);
                                expressCheckoutContainer.style.display = 'none';
                            }
                        }
                    };

                    // Listen for input changes on name and email fields
                    if (donorName) {
                        donorName.addEventListener('input', checkAndMountExpressCheckout);
                        donorName.addEventListener('blur', checkAndMountExpressCheckout);
                    }
                    if (donorEmail) {
                        donorEmail.addEventListener('input', checkAndMountExpressCheckout);
                        donorEmail.addEventListener('blur', checkAndMountExpressCheckout);
                    }
                }
            } else {
                // Stripe: Create PaymentIntent or SetupIntent
                const response = await fetch(basePath + '/api/create-payment-intent.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        amount: selectedAmount,
                        frequency: frequency,
                        campaign_id: campaignId,
                        csrf_token: CONFIG.csrfToken
                    })
                });

                const data = await response.json();

                if (data.error) {
                    throw new Error(data.error);
                }

                clientSecret = data.clientSecret;
                donationId = data.donationId;
                paymentMode = data.mode || 'payment';

                // Initialize Payment Elements
                const appearance = {
                    theme: 'stripe',
                    variables: {
                        colorPrimary: '#20a39e',
                        colorBackground: '#ffffff',
                        colorText: '#333333',
                        fontFamily: 'Montserrat, sans-serif',
                        borderRadius: '8px'
                    }
                };

                elements = stripe.elements({ clientSecret, appearance });
                paymentElement = elements.create('payment');
                paymentElement.mount('#payment-element');

                // Show payment step, hide amount step
                amountStep.style.display = 'none';
                if (amountSelectionContainer) amountSelectionContainer.style.display = 'none';
                paymentStep.style.display = 'block';

                // Update button text for monthly
                if (frequency === 'monthly') {
                    document.getElementById('button-text').textContent =
                        `Start ${currencySymbol}${selectedAmount}/month Donation`;
                } else {
                    document.getElementById('button-text').textContent = 'Complete Donation';
                }
            }
        } catch (error) {
            showMessage(error.message, 'error');
        } finally {
            stripeBtn.disabled = false;
            stripeBtn.innerHTML = '<span class="pay-icon">💳</span> Continue to Payment';
        }
    }

    // Back button
    if (backBtn) {
        backBtn.addEventListener('click', () => {
            paymentStep.style.display = 'none';
            amountStep.style.display = 'flex';
            if (amountSelectionContainer) amountSelectionContainer.style.display = 'block';

            // Cleanup Stripe elements if present
            if (paymentElement) {
                paymentElement.unmount();
                paymentElement = null;
            }

            // Clear PayArc inputs
            if (cardNumberInput) cardNumberInput.value = '';
            if (cardExpiryInput) cardExpiryInput.value = '';
            if (cardCvvInput) cardCvvInput.value = '';
        });
    }

    // Stripe button click
    if (stripeBtn) {
        stripeBtn.addEventListener('click', showPaymentStep);
    }

    // Submit payment
    if (submitBtn) {
        submitBtn.addEventListener('click', async () => {
            if (!donorName.value.trim()) {
                showMessage('Please enter your name', 'error');
                donorName.focus();
                return;
            }

            if (!donorEmail.value.trim() || !isValidEmail(donorEmail.value)) {
                showMessage('Please enter a valid email', 'error');
                donorEmail.focus();
                return;
            }

            setLoading(true);

            try {
                if (CONFIG.payarcEnabled) {
                    // PayArc Direct API payment
                    await processPayArcPayment();
                } else {
                    // Stripe payment
                    await processStripePayment();
                }
            } catch (err) {
                console.error('Payment error:', err);
                showMessage(err.message || 'Payment failed. Please try again.', 'error');
                setLoading(false);
            }
        });
    }

    // PayArc payment processing
    async function processPayArcPayment() {
        // Validate card inputs
        const cardNumber = cardNumberInput ? cardNumberInput.value.replace(/\s/g, '') : '';
        const expiry = cardExpiryInput ? cardExpiryInput.value : '';
        const cvv = cardCvvInput ? cardCvvInput.value : '';

        if (!cardNumber || cardNumber.length < 13) {
            throw new Error('Please enter a valid card number');
        }

        const [expMonth, expYear] = expiry.split('/');
        if (!expMonth || !expYear) {
            throw new Error('Please enter a valid expiry date (MM/YY)');
        }

        if (!cvv || cvv.length < 3) {
            throw new Error('Please enter a valid CVV');
        }

        const action = frequency === 'monthly' ? 'subscribe' : 'charge';

        const response = await fetch(basePath + '/api/process-payarc.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: action,
                amount: selectedAmount,
                card_number: cardNumber,
                exp_month: parseInt(expMonth),
                exp_year: parseInt(expYear),
                cvv: cvv,
                donor_name: donorName.value.trim(),
                donor_email: donorEmail.value.trim(),
                display_name: displayName ? displayName.value.trim() : '',
                donation_message: donationMessageInput ? donationMessageInput.value.trim() : '',
                is_anonymous: isAnonymous ? isAnonymous.checked : false,
                campaign_id: campaignId,
                csrf_token: CONFIG.csrfToken
            })
        });

        const result = await response.json();

        if (result.error) {
            throw new Error(result.error);
        }

        if (result.success) {
            const successUrl = basePath + '/success.php?id=' + result.donationId +
                (campaignId ? '&campaign=' + campaignId : '');
            window.location.href = successUrl;
        } else {
            throw new Error('Payment was not completed. Please try again.');
        }
    }

    // Stripe payment processing
    async function processStripePayment() {
        let result;

        if (paymentMode === 'subscription') {
            // For subscriptions, use confirmSetup
            result = await stripe.confirmSetup({
                elements,
                confirmParams: {
                    return_url: window.location.origin + basePath + '/success.php'
                },
                redirect: 'if_required'
            });
        } else {
            // For one-time payments, use confirmPayment
            result = await stripe.confirmPayment({
                elements,
                confirmParams: {
                    return_url: window.location.origin + basePath + '/success.php',
                    receipt_email: donorEmail.value.trim()
                },
                redirect: 'if_required'
            });
        }

        if (result.error) {
            if (result.error.type === 'card_error' || result.error.type === 'validation_error') {
                showMessage(result.error.message, 'error');
            } else {
                showMessage('An unexpected error occurred.', 'error');
            }
            setLoading(false);
            return;
        }

        // Get the intent ID based on mode
        const intentId = paymentMode === 'subscription'
            ? result.setupIntent?.id
            : result.paymentIntent?.id;

        const intentStatus = paymentMode === 'subscription'
            ? result.setupIntent?.status
            : result.paymentIntent?.status;

        if (intentId && intentStatus === 'succeeded') {
            // Confirm payment/subscription on server
            const confirmResponse = await fetch(basePath + '/api/confirm-payment.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    mode: paymentMode,
                    intent_id: intentId,
                    donor_name: donorName.value.trim(),
                    donor_email: donorEmail.value.trim(),
                    display_name: displayName ? displayName.value.trim() : '',
                    donation_message: donationMessageInput ? donationMessageInput.value.trim() : '',
                    is_anonymous: isAnonymous ? isAnonymous.checked : false,
                    amount: selectedAmount,
                    campaign_id: campaignId
                })
            });

            const confirmData = await confirmResponse.json();

            if (confirmData.success) {
                // Redirect with campaign context
                const successUrl = basePath + '/success.php?id=' + confirmData.donationId +
                    (campaignId ? '&campaign=' + campaignId : '');
                window.location.href = successUrl;
            } else {
                showMessage(confirmData.error || 'Payment confirmation failed', 'error');
                setLoading(false);
            }
        } else {
            showMessage('Payment was not completed. Please try again.', 'error');
            setLoading(false);
        }
    }

    // Helper functions
    function showMessage(text, type = 'info') {
        if (!paymentMessage) return;
        paymentMessage.textContent = text;
        paymentMessage.className = 'payment-message ' + type;
        paymentMessage.style.display = 'block';
    }

    function setLoading(isLoading) {
        if (!submitBtn) return;
        submitBtn.disabled = isLoading;
        const buttonText = document.getElementById('button-text');
        const spinner = document.getElementById('spinner');
        if (buttonText) buttonText.style.display = isLoading ? 'none' : 'inline';
        if (spinner) spinner.style.display = isLoading ? 'inline-block' : 'none';
    }

    function isValidEmail(email) {
        return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
    }

    // PayPal integration
    if (typeof paypal !== 'undefined' && CONFIG.paypalClientId) {
        paypal.Buttons({
            style: {
                layout: 'horizontal',
                color: 'gold',
                shape: 'rect',
                label: 'paypal',
                height: 45
            },

            createOrder: async (data, actions) => {
                if (selectedAmount < 1) {
                    alert('Please select or enter a valid amount');
                    return;
                }

                const response = await fetch(basePath + '/api/process-paypal.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'create',
                        amount: selectedAmount,
                        frequency: frequency,
                        campaign_id: campaignId,
                        csrf_token: CONFIG.csrfToken
                    })
                });

                const result = await response.json();
                if (result.error) {
                    throw new Error(result.error);
                }

                return result.orderId;
            },

            onApprove: async (data, actions) => {
                const response = await fetch(basePath + '/api/process-paypal.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'capture',
                        orderId: data.orderID,
                        campaign_id: campaignId,
                        csrf_token: CONFIG.csrfToken
                    })
                });

                const result = await response.json();

                if (result.success) {
                    const successUrl = basePath + '/success.php?id=' + result.donationId +
                        (campaignId ? '&campaign=' + campaignId : '');
                    window.location.href = successUrl;
                } else {
                    alert('Payment failed: ' + (result.error || 'Unknown error'));
                }
            },

            onError: (err) => {
                console.error('PayPal error:', err);
                alert('PayPal encountered an error. Please try again.');
            }
        }).render('#paypal-button-container');
    }

    // Smooth scroll for donate anchor
    document.querySelectorAll('a[href="#donate"]').forEach(anchor => {
        anchor.addEventListener('click', (e) => {
            e.preventDefault();
            const target = document.getElementById('donate');
            if (target) {
                target.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        });
    });

    // Tab switching functionality
    const tabBtns = document.querySelectorAll('.tab-btn');
    const tabContents = document.querySelectorAll('.tab-content');

    tabBtns.forEach(btn => {
        btn.addEventListener('click', () => {
            const tabId = btn.dataset.tab;

            // Update button states
            tabBtns.forEach(b => b.classList.remove('active'));
            btn.classList.add('active');

            // Update content visibility
            tabContents.forEach(content => {
                content.classList.remove('active');
                if (content.id === 'tab-' + tabId) {
                    content.classList.add('active');
                }
            });
        });
    });

    // Initialize matching display on load
    updateMatchingDisplay();
});
