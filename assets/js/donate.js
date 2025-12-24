/**
 * Donation Page JavaScript
 * Handles amount selection, Stripe Payment Elements, and PayPal integration
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

    // Initialize Stripe
    if (CONFIG.stripeKey) {
        stripe = Stripe(CONFIG.stripeKey);
    }

    // Amount button click
    amountBtns.forEach(btn => {
        btn.addEventListener('click', () => {
            amountBtns.forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            selectedAmount = parseInt(btn.dataset.amount);
            customInput.value = '';
        });
    });

    // Custom amount input
    if (customInput) {
        customInput.addEventListener('input', (e) => {
            const val = parseInt(e.target.value);
            if (val > 0) {
                amountBtns.forEach(b => b.classList.remove('active'));
                selectedAmount = val;
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
            // Create PaymentIntent
            const response = await fetch('api/create-payment-intent.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    amount: selectedAmount,
                    frequency: frequency,
                    csrf_token: CONFIG.csrfToken
                })
            });

            const data = await response.json();

            if (data.error) {
                throw new Error(data.error);
            }

            clientSecret = data.clientSecret;
            donationId = data.donationId;

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

            // Update step indicator
            document.querySelector('.card-step').textContent = 'PAYMENT • 2/2';

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
            document.querySelector('.card-step').textContent = 'AMOUNT • 1/2';

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
                const { error, paymentIntent } = await stripe.confirmPayment({
                    elements,
                    confirmParams: {
                        return_url: window.location.origin + '/success.php',
                        receipt_email: donorEmail.value.trim()
                    },
                    redirect: 'if_required'
                });

                if (error) {
                    if (error.type === 'card_error' || error.type === 'validation_error') {
                        showMessage(error.message, 'error');
                    } else {
                        showMessage('An unexpected error occurred.', 'error');
                    }
                    setLoading(false);
                    return;
                }

                if (paymentIntent && paymentIntent.status === 'succeeded') {
                    // Confirm payment on server
                    const confirmResponse = await fetch('api/confirm-payment.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            payment_intent_id: paymentIntent.id,
                            donor_name: donorName.value.trim(),
                            donor_email: donorEmail.value.trim()
                        })
                    });

                    const confirmData = await confirmResponse.json();

                    if (confirmData.success) {
                        window.location.href = 'success.php?id=' + confirmData.donationId;
                    } else {
                        showMessage(confirmData.error || 'Payment confirmation failed', 'error');
                        setLoading(false);
                    }
                }

            } catch (err) {
                showMessage('Payment failed. Please try again.', 'error');
                setLoading(false);
            }
        });
    }

    // Helper functions
    function showMessage(text, type = 'info') {
        paymentMessage.textContent = text;
        paymentMessage.className = 'payment-message ' + type;
        paymentMessage.style.display = 'block';
    }

    function setLoading(isLoading) {
        submitBtn.disabled = isLoading;
        document.getElementById('button-text').style.display = isLoading ? 'none' : 'inline';
        document.getElementById('spinner').style.display = isLoading ? 'inline-block' : 'none';
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

                const response = await fetch('api/process-paypal.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'create',
                        amount: selectedAmount,
                        frequency: frequency,
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
                const response = await fetch('api/process-paypal.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'capture',
                        orderId: data.orderID,
                        csrf_token: CONFIG.csrfToken
                    })
                });

                const result = await response.json();

                if (result.success) {
                    window.location.href = 'success.php?id=' + result.donationId;
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
});
