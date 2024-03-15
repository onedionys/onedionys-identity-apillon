const express = require('express');
const {
    Identity
} = require('@apillon/sdk');

const app = express();
const port = 3000;

app.use(express.json());

const validateApiKeyAndSecret = (req, res, next) => {
    const apiKey = req.headers['x-api-key'];
    const apiSecret = req.headers['x-api-secret'];

    if (!apiKey || !apiSecret) {
        return res.status(401).json({
            error: 'Missing API key or secret'
        });
    }

    req.identity = new Identity({
        key: apiKey,
        secret: apiSecret
    });

    next();
};

app.post('/generate-message', validateApiKeyAndSecret, (req, res) => {
    const data = `Test message from One Dionys`;
    const message = req.identity.generateSigningMessage(data);
    res.json({
        message: message.message
    });
});

app.post('/validate-signature', validateApiKeyAndSecret, (req, res) => {
    const message = `Identity EVM SDK test\n1703683380574`;
    const wallet = `0x65266dbf8259968f54747bc83155238370d3808a`;
    const signature = `0xe576fcf2d3bbe335ec542faab8701eff263f6ad068709a32d5d714e7d9a01c82353a6639a3206376b5c3e278bb5276595b4ecd67a22da58c16f5cb1f9026478a1c`;

    const {
        isValid,
        address
    } = req.identity.validateEvmWalletSignature({
        walletAddress: wallet,
        message,
        signature,
    });

    res.json({
        isValid,
        address
    });
});

app.post('/wallet-identity', validateApiKeyAndSecret, async (req, res) => {
    const walletAddress = `5HB6TahxS9KpSAq69tqjvU7VLuzKsVkCpPSPULEYixrqvn1V`;
    const info = await req.identity.getWalletIdentity(walletAddress);
    res.json(info);
});

app.listen(port, () => {
    console.log(`Server is running on http://localhost:${port}`);
});
