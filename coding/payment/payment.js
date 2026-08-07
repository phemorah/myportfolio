const form = document.querySelector('#payment-request-form');
const methodField = document.querySelector('#payment-method');
const currencyField = document.querySelector('#payment-currency');
const status = document.querySelector('#form-status');
const submitButton = form.querySelector('button[type="submit"]');

document.querySelector('#year').textContent = new Date().getFullYear();

document.querySelectorAll('.method-button').forEach((button) => {
  button.addEventListener('click', () => {
    methodField.value = button.dataset.method;
    currencyField.value = button.dataset.currency;
    document.querySelector('#request').scrollIntoView({ behavior: 'smooth' });
    setTimeout(() => form.querySelector('[name="name"]').focus(), 500);
  });
});

form.addEventListener('submit', async (event) => {
  event.preventDefault();
  if (!form.reportValidity()) return;

  submitButton.disabled = true;
  submitButton.textContent = 'Sending request…';
  status.className = 'form-status';
  status.textContent = 'Saving your request securely…';

  try {
    const response = await fetch(form.action, { method: 'POST', body: new FormData(form), headers: { Accept: 'application/json' } });
    const result = await response.json();
    if (!response.ok || !result.ok) throw new Error(result.message || 'Request failed');
    status.className = 'form-status success';
    status.textContent = `Request received${result.reference ? ` — reference ${result.reference}` : ''}. Femi will send your private payment details after confirming your application.`;
    form.reset();
  } catch (error) {
    status.className = 'form-status error';
    status.innerHTML = 'The request could not be saved. Please <a href="https://wa.me/2348033979829" target="_blank" rel="noopener">contact Femi on WhatsApp</a>.';
  } finally {
    submitButton.disabled = false;
    submitButton.innerHTML = 'Request payment details <span>→</span>';
  }
});
