const form = document.querySelector('#payment-request-form');
const methodField = document.querySelector('#payment-method');
const currencyField = document.querySelector('#payment-currency');
const status = document.querySelector('#form-status');
const submitButton = form.querySelector('button[type="submit"]');
const transferSection = document.querySelector('#transfer-confirmation');
const evidenceForm = document.querySelector('#evidence-form');
const evidenceStatus = document.querySelector('#evidence-status');
const evidenceSuccess = document.querySelector('#evidence-success');

document.querySelector('#year').textContent = new Date().getFullYear();

document.querySelectorAll('.method-button').forEach((button) => {
  button.addEventListener('click', () => {
    methodField.value = button.dataset.method;
    currencyField.value = button.dataset.currency;
    document.querySelector('#request').scrollIntoView({ behavior: 'smooth' });
    setTimeout(() => form.querySelector('[name="email"]').focus(), 500);
  });
});

methodField.addEventListener('change', () => {
  currencyField.value = methodField.value === 'Direct NGN bank transfer' ? 'NGN' : methodField.value ? 'USD' : '';
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
    if (result.bank_details && methodField.value === 'Direct NGN bank transfer') {
      document.querySelector('#bank-name').textContent = result.bank_details.bank;
      document.querySelector('#account-name').textContent = result.bank_details.account_name;
      document.querySelector('#account-number').textContent = result.bank_details.account_number;
      document.querySelector('#bank-amount').textContent = result.bank_details.formatted_amount;
      document.querySelector('#transfer-reference').textContent = result.reference;
      document.querySelector('#evidence-reference').value = result.reference;
      document.querySelector('#evidence-email').value = form.elements.email.value;
      transferSection.hidden = false;
      transferSection.scrollIntoView({ behavior: 'smooth' });
    }
    form.reset();
  } catch (error) {
    status.className = 'form-status error';
    status.innerHTML = 'The request could not be saved. Please <a href="https://wa.me/2348033979829" target="_blank" rel="noopener">contact Femi on WhatsApp</a>.';
  } finally {
    submitButton.disabled = false;
    submitButton.innerHTML = 'Request payment details <span>→</span>';
  }
});

document.querySelector('#copy-account').addEventListener('click', async (event) => {
  await navigator.clipboard.writeText(document.querySelector('#account-number').textContent);
  event.currentTarget.textContent = 'Copied';
  setTimeout(() => { event.currentTarget.textContent = 'Copy'; }, 1500);
});

evidenceForm.addEventListener('submit', async (event) => {
  event.preventDefault();
  if (!evidenceForm.reportValidity()) return;
  const button = evidenceForm.querySelector('button[type="submit"]');
  button.disabled = true;
  button.textContent = 'Uploading securely…';
  evidenceStatus.className = 'form-status';
  evidenceStatus.textContent = 'Uploading your evidence…';
  try {
    const response = await fetch(evidenceForm.action, { method: 'POST', body: new FormData(evidenceForm), headers: { Accept: 'application/json' } });
    const result = await response.json();
    if (!response.ok || !result.ok) throw new Error(result.message || 'Upload failed');
    document.querySelector('#success-reference').textContent = result.reference;
    evidenceForm.reset();
    evidenceForm.hidden = true;
    evidenceSuccess.hidden = false;
    history.replaceState(null, '', '#payment-received');
    evidenceSuccess.scrollIntoView({ behavior: 'smooth', block: 'center' });
    setTimeout(() => evidenceSuccess.focus({ preventScroll: true }), 450);
  } catch (error) {
    evidenceStatus.className = 'form-status error';
    evidenceStatus.textContent = error.message || 'The evidence could not be uploaded. Please contact Femi.';
    button.disabled = false;
    button.innerHTML = 'Submit for verification <span>→</span>';
  }
});
