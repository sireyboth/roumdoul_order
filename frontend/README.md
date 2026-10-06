# Roumdoul Order customer site (Next.js)

- `/t/{qr-token}`: the menu a customer sees after scanning a table QR
- `/staff`: kitchen, waiter and cashier screens (Step 1)

```bat
copy .env.example .env.local
npm install
npm run dev
```

`API_URL` in `.env.local` points to the Laravel backend (default `http://127.0.0.1:8000`).
