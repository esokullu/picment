# AI Featured Image Generator

**AI Featured Image Generator** is a powerful WordPress plugin that leverages OpenAI's DALL-E 3 model to automatically create stunning, relevant featured images for your blog posts. Save time and ensure your content is always visually engaging with high-quality AI-generated art.

## 🚀 Key Features

- **Auto-generate on Publish**: Automatically creates a featured image the moment you publish a new post.
- **Bulk Generation**: Generate images for all your existing posts from a single admin page with a real-time progress bar.
- **DALL-E 3 Quality**: Uses OpenAI's most advanced image generation model for superior results.
- **Per-Post Control**: Enable/disable auto-generation for specific posts or trigger manual generation with one click via the post editor sidebar.
- **Customizable Prompts**: Use the built-in prompt or create your own template using `{title}` and `{content}` placeholders.
- **Flexible Image Settings**: Choose between Landscape (1792x1024), Square (1024x1024), or Portrait (1024x1792) orientations, plus quality (HD/Standard) and style (Vivid/Natural) options.
- **Media Library Integration**: All generated images are automatically downloaded and stored in your local WordPress Media Library.
- **Block & FSE Compatible**: Works seamlessly with modern block themes and Full Site Editing.

## 💳 Billing Modes

The plugin offers flexible ways to access AI generation:

1.  **Bring Your Own Key (BYOK)**: Use your own OpenAI API key. Pay OpenAI directly with a rate limit of 10 requests/minute.
2.  **Subscription Plans**: Hassle-free managed access with monthly credits.
    - **Starter** ($7/mo): 20 credits
    - **Pro** ($19/mo): 100 credits
    - **Agency** ($49/mo): 400 credits
3.  **Free Trial**: New installations receive 1 free credit to test the service instantly.

## 🛠 Installation

1.  Download the plugin and upload the `wp-ai-image` folder to your `/wp-content/plugins/` directory.
2.  Activate the plugin through the **Plugins** menu in WordPress.
3.  Navigate to **AI Image Gen → Billing** to choose your preferred billing mode or enter your API key.

## 📖 Requirements

- WordPress 6.0 or higher
- PHP 7.4 or higher
- Outbound HTTPS access for API communication

## 🔒 Privacy & Security

In BYOK mode, your API key is stored securely on your server, and requests are sent directly to OpenAI. We do not host your images; they are stored directly in your WordPress Media Library.

## 📄 License

This project is licensed under the GPLv2 or later. See the [LICENSE](https://www.gnu.org/licenses/gpl-2.0.html) for details.

---
*Developed by Barack Sokullu*


## 📊 Competitive Landscape

There are several AI image generation plugins available for WordPress, but **AI Featured Image Generator** offers a unique combination of features that sets it apart.

| Feature | AI Featured Image Generator | AI Featured Image (WPRaptor) | Artist Image Generator | Featured Image Creator AI |
|:---|:---:|:---:|:---:|:---:|
| **Subscription Plans** | ✅ | ❌ | Partial (Credits) | ❌ |
| **Free Trial Credit** | ✅ | ❌ | ❌ | ❌ |
| **Bulk Generation** | ✅ | ❌ | ❌ | ✅ |
| **Per-Post Control** | ✅ | ✅ | ❌ | ✅ |
| **Auto-generate on Publish** | ✅ | ❌ | ❌ | ✅ |
| **BYOK Mode** | ✅ | ✅ | ✅ | ✅ |
| **Multiple AI Providers** | ❌ | ✅ | ✅ | ✅ |
| **WooCommerce Integration** | ❌ | ❌ | ✅ | ❌ |
| **Built-in Stripe Billing** | ✅ | ❌ | ❌ | ❌ |
| **Localization (11+ Languages)** | ✅ | ❌ | ❌ | Partial |

While other plugins may offer more AI provider options, this plugin is the only one that provides a seamless, all-in-one solution with **built-in Stripe subscription billing** and a **free trial credit**, making it the easiest and most accessible option for users who want to get started with AI image generation without managing their own API keys or external accounts.
