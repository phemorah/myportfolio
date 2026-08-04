const form = document.querySelector('#application-form');
const year = document.querySelector('#year');
const note = document.querySelector('#form-note');
const submitButton = form.querySelector('button[type="submit"]');

year.textContent = new Date().getFullYear();

form.addEventListener('submit', async (event) => {
  event.preventDefault();
  if (!form.reportValidity()) return;

  const data = new FormData(form);
  const message = [
    'Hi Femi, I would like to apply for the coding pilot programme.',
    '',
    `Name: ${data.get('name')}`,
    `Email: ${data.get('email')}`,
    `WhatsApp: ${data.get('phone')}`,
    '',
    `My goal: ${data.get('goal')}`
  ].join('\n');
  const whatsappUrl = `https://wa.me/2348033979829?text=${encodeURIComponent(message)}`;
  const whatsappWindow = window.open('', '_blank');

  submitButton.disabled = true;
  submitButton.textContent = 'Saving application…';
  note.className = 'form-note';
  note.textContent = 'Saving your application securely…';

  try {
    const response = await fetch(form.action, {
      method: 'POST',
      body: data,
      headers: { Accept: 'application/json' }
    });
    const result = await response.json();
    if (!response.ok || !result.ok) throw new Error(result.message || 'Submission failed');

    note.className = 'form-note success';
    note.textContent = 'Application saved. Opening WhatsApp so you can send your message to Femi.';
    form.reset();
  } catch (error) {
    note.className = 'form-note error';
    note.textContent = 'Email/storage was unavailable, but you can still complete your application on WhatsApp.';
  } finally {
    if (whatsappWindow) whatsappWindow.location.href = whatsappUrl;
    else window.location.href = whatsappUrl;
    submitButton.disabled = false;
    submitButton.innerHTML = 'Send application <span>→</span>';
  }
});
