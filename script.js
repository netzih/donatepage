document.addEventListener('DOMContentLoaded', () => {
    const amountBtns = document.querySelectorAll('.amount-btn');
    const customAmountInput = document.getElementById('custom-amount');
    const displayAmount = document.getElementById('display-amount');
    const submitBtn = document.getElementById('submit-donation');
    const frequencyRadios = document.querySelectorAll('input[name="frequency"]');
    
    let currentAmount = 25;

    // Update display amount
    const updateDisplay = (amount) => {
        const formattedAmount = parseFloat(amount).toFixed(2);
        displayAmount.textContent = `$${formattedAmount}`;
    };

    // Amount buttons logic
    amountBtns.forEach(btn => {
        btn.addEventListener('click', () => {
            amountBtns.forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            customAmountInput.value = '';
            currentAmount = btn.dataset.amount;
            updateDisplay(currentAmount);
        });
    });

    // Custom amount input logic
    customAmountInput.addEventListener('input', (e) => {
        const value = e.target.value;
        if (value > 0) {
            amountBtns.forEach(b => b.classList.remove('active'));
            currentAmount = value;
            updateDisplay(currentAmount);
        } else if (value === '') {
            // Default back to last active button if custom is cleared
            const activeBtn = document.querySelector('.amount-btn.active');
            if (activeBtn) {
                currentAmount = activeBtn.dataset.amount;
                updateDisplay(currentAmount);
            }
        }
    });

    // Submit donation logic
    submitBtn.addEventListener('click', () => {
        const frequency = document.querySelector('input[name="frequency"]:checked').value;
        
        // Mock submission animation
        submitBtn.disabled = true;
        const originalText = submitBtn.innerHTML;
        submitBtn.innerHTML = 'Processing...';
        
        setTimeout(() => {
            submitBtn.innerHTML = 'Thank You! ❤️';
            submitBtn.style.background = '#008080';
            
            console.log(`Donation submitted: $${currentAmount} (${frequency})`);
            
            // Reset after 3 seconds
            setTimeout(() => {
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalText;
                submitBtn.style.background = '';
            }, 3000);
        }, 1500);
    });

    // Intersection Observer for animations
    const observerOptions = {
        threshold: 0.1
    };

    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.classList.add('active');
            }
        });
    }, observerOptions);

    document.querySelectorAll('.fade-up').forEach(el => {
        observer.observe(el);
    });
});
