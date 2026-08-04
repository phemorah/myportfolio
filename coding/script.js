const form = document.querySelector('#application-form');
const year = document.querySelector('#year');

year.textContent = new Date().getFullYear();

form.addEventListener('submit', (event) => {
  event.preventDefault();
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

  window.open(`https://wa.me/2348033979829?text=${encodeURIComponent(message)}`, '_blank', 'noopener');
});
