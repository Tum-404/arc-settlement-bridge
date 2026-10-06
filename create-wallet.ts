import { initiateDeveloperControlledWalletsClient } from '@circle-fin/developer-controlled-wallets';

const client = initiateDeveloperControlledWalletsClient({
    apiKey: process.env.CIRCLE_API_KEY!,
    entitySecret: process.env.CIRCLE_ENTITY_SECRET!,
});

async function main() {
    const walletSetResponse = await client.createWalletSet({
        name: 'John Wallet',
    });

    const walletSet = walletSetResponse.data?.walletSet;
    if (!walletSet?.id) {
        throw new Error('Wallet set creation failed: no ID returned');
    }

    const walletResponse = await client.createWallets({
        walletSetId: walletSet.id,
        blockchains: ['ARC-TESTNET'], // Can be any supported blockchain
        count: 1,
        accountType: 'EOA', // Can be EOA or SCA
    });

    console.log('Wallet set response:', walletSetResponse.data);
    console.log('Wallet response:', walletResponse.data);
}

main().catch((err) => {
    console.error('Error:', err.message || err);
    process.exit(1);
});
