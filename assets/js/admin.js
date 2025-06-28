document.addEventListener('DOMContentLoaded', function () {
    const generateBtn = document.getElementById('ncme_generate_key_button');
    const secretField = document.getElementById('secret_token');

    // Jika butang atau medan tidak wujud, jangan buat apa-apa
    if (!generateBtn || !secretField) {
        return;
    }

    // Fungsi untuk menjana rentetan rawak
    function generateRandomString(length) {
        let result = '';
        const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        const charsLength = chars.length;
        for (let i = 0; i < length; i++) {
            result += chars.charAt(Math.floor(Math.random() * charsLength));
        }
        return result;
    }

    // Tambah event listener pada butang
    generateBtn.addEventListener('click', function (event) {
        // Halang butang dari submit form
        event.preventDefault();

        // Jana kunci baru dan letakkan dalam medan input
        secretField.value = generateRandomString(40);
    });
});