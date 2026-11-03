      # Product CMS setup

## Production on Netlify

1. Push this folder to a GitHub repository.
2. Create a new site in Netlify and connect it to the repository.
3. In Netlify, enable **Identity** under `Site configuration > Identity`.
4. Enable **Git Gateway** under `Identity > Services > Git Gateway`.
5. Invite the store owner from `Identity > Invite users`.
6. Publish the site. Open `/admin/` and sign in.
7. Add, edit, delete, or reorder products and upload images from the **Store Products > Products** editor. Publishing saves them to the repository and updates the website.

## Local editing

1. Install Node.js and run:

   ```bash
   npx decap-server
   ```

2. From a second terminal, serve the website:

   ```bash
   npx serve .
   ```

3. Open `http://localhost:3000/admin/`.

## Notes

- The CMS product data is stored in `content/products.json`.
- Uploaded images are stored in `uploads/`.
- The website first loads CMS data and falls back to its built-in catalog if the JSON cannot be loaded.
