/**
 * Campaign Page JavaScript
 * Handles campaign-specific donation flow with matching display
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
    const backBtn = document.getElementById('back-btn');
    const submitBtn = document.getElementById('submit-payment');
    const paymentMessage = document.getElementById('payment-message');
    const donorName = document.getElementById('donor-name');
    const donorEmail = document.getElementById('donor-email');

    // Matching display elements
    const donationAmountEl = document.getElementById('donation-amount');
    const matchedAmountEl = document.getElementById('matched-amount');

    // Initialize Stripe
    if (CONFIG.stripeKey) {
        stripe = Stripe(CONFIG.stripeKey);
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
            // Create PaymentIntent or SetupIntent
            const response = await fetch('/api/create-payment-intent.php', {
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
            paymentStep.style.display = 'block';

            // Update button text for monthly
            if (frequency === 'monthly') {
                document.getElementById('button-text').textContent =
                    `Start ${currencySymbol}${selectedAmount}/month Donation`;
            } else {
                document.getElementById('button-text').textContent = 'Complete Donation';
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

            // Cleanup
            if (paymentElement) {
                paymentElement.unmount();
                paymentElement = null;
            }
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
                let result;

                if (paymentMode === 'subscription') {
                    // For subscriptions, use confirmSetup
                    result = await stripe.confirmSetup({
                        elements,
                        confirmParams: {
                            return_url: window.location.origin + '/success.php'
                        },
                        redirect: 'if_required'
                    });
                } else {
                    // For one-time payments, use confirmPayment
                    result = await stripe.confirmPayment({
                        elements,
                        confirmParams: {
                            return_url: window.location.origin + '/success.php',
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
                    const confirmResponse = await fetch('/api/confirm-payment.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            mode: paymentMode,
                            intent_id: intentId,
                            donor_name: donorName.value.trim(),
                            donor_email: donorEmail.value.trim(),
                            amount: selectedAmount,
                            campaign_id: campaignId
                        })
                    });

                    const confirmData = await confirmResponse.json();

                    if (confirmData.success) {
                        // Redirect with campaign context
                        const successUrl = '/success.php?id=' + confirmData.donationId +
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

            } catch (err) {
                console.error('Payment error:', err);
                showMessage('Payment failed. Please try again.', 'error');
                setLoading(false);
            }
        });
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

                const response = await fetch('/api/process-paypal.php', {
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
                const response = await fetch('/api/process-paypal.php', {
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
                    const successUrl = '/success.php?id=' + result.donationId +
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

    // Initialize matching display on load
    updateMatchingDisplay();
});
