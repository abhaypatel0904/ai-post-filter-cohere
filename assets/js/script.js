document.addEventListener('DOMContentLoaded', function () {
    const micBtn = document.getElementById('ai_voice_btn');
    const promptInput = document.getElementById('ai_prompt');
    const promptPlaceholder = promptInput.placeholder;

    if (!micBtn || !promptInput) {
        return;
    }

    if ('webkitSpeechRecognition' in window) {
        const recognition = new webkitSpeechRecognition();
        recognition.continuous = false;
        recognition.interimResults = false;
        recognition.lang = 'en-US';

        micBtn.addEventListener('click', function () {
            promptInput.placeholder = 'Listening...';
            recognition.start();
        });

        recognition.onresult = function (event) {
            const transcript = event.results[0][0].transcript;
            promptInput.value = transcript;
            promptInput.form.submit(); // auto-submit after recognition
        };

        recognition.onend = function () {
            promptInput.placeholder = promptPlaceholder;
        };

        recognition.onerror = function (event) {
            console.error('Voice recognition error:', event.error);
        };
    } else {
        micBtn.disabled = true;
        micBtn.title = 'Voice recognition not supported in your browser';
    }
});