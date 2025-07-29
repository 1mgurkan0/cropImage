require('dotenv').config();

const { GoogleGenerativeAI } = require('@google/generative-ai');
const readline = require('readline');

async function runGeminiChat() {
    const API_KEY = process.env.GOOGLE_API_KEY;

    if (!API_KEY) {
        console.error("Hata: GOOGLE_API_KEY ortam değişkeni ayarlanmamış. Lütfen '.env' dosyasını kontrol edin ve API anahtarınızı ekleyin.");
        return;
    }

    const genAI = new GoogleGenerativeAI(API_KEY);

    const modelId = 'gemini-2.0-flash';
    const model = genAI.getGenerativeModel({ model: modelId });

    const chat = model.startChat({
        history: [],
        generationConfig: {
            maxOutputTokens: 200,
        },
    });

    console.log("Gemini ile sohbete başlayın (çıkmak için 'exit' yazın).");

    const rl = readline.createInterface({
        input: process.stdin,
        output: process.stdout
    });

    rl.on('line', async (input) => {
        if (input.toLowerCase() === 'exit') {
            console.log("Sohbet sonlandırıldı.");
            rl.close();
            return;
        }

        try {
            const result = await chat.sendMessage(input);
            const response = await result.response;
            const text = response.text();
            console.log(`Gemini: ${text}`);
        } catch (error) {
            console.error("Bir hata oluştu:", error.message);
            if (error.status && error.statusText) {
                console.error(`HTTP Durum Kodu: ${error.status} - ${error.statusText}`);
            }
            if (error.details) {
                console.error("Hata Detayları:", JSON.stringify(error.details, null, 2));
            }
        }
        process.stdout.write("Siz: ");
    });

    process.stdout.write("Siz: ");
}

runGeminiChat();
