/**
 * Donation Page JavaScript
 * Handles amount selection, Stripe checkout, and PayPal integration
 */

document.addEventListener('DOMContentLoaded', () => {
    // State
    let selectedAmount = 100;
    let frequency = 'once';

    // Elements
    const amountBtns = document.querySelectorAll('.amount-btn');
    const freqBtns = document.querySelectorAll('.freq-btn');
    const customInput = document.getElementById('custom-amount');
    const stripeBtn = document.getElementById('stripe-btn');

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

    // Stripe checkout
    if (stripeBtn && CONFIG.stripeKey) {
        const stripe = Stripe(CONFIG.stripeKey);

        stripeBtn.addEventListener('click', async () => {
            if (selectedAmount < 1) {
                alert('Please select or enter a valid amount');
                return;
            }

            stripeBtn.disabled = true;
            stripeBtn.classList.add('loading');
            stripeBtn.textContent = 'Processing...';

            try {
                const response = await fetch('api/process-stripe.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
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

                // Redirect to Stripe Checkout
                const result = await stripe.redirectToCheckout({
                    sessionId: data.sessionId
                });

                if (result.error) {
                    throw new Error(result.error.message);
                }
            } catch (error) {
                alert('Error: ' + error.message);
                stripeBtn.disabled = false;
                stripeBtn.classList.remove('loading');
                stripeBtn.innerHTML = '<span class="pay-icon">💳</span> Pay with Card';
            }
        });
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
                    headers: {
                        'Content-Type': 'application/json',
                    },
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
                    headers: {
                        'Content-Type': 'application/json',
                    },
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
