<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<style>

    .fluent-cart-sandbox-connect-manually {
        max-width: 400px;
        width: 100%;
        padding: 24px;
        margin: 35px auto 0;
        border-radius: 7px;
    }
    form {
        max-width: 768px;
        width: 100%;
    }
    .header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 8px;
    }
    p {
        color: #6b7280;
        font-size: 16px;
        line-height: 24px;
        margin-top: 0;
        margin-bottom: 24px;
        max-width: 600px;
    }
    a {
        color: #2563eb;
        text-decoration: underline;
    }
    label {
        font-weight: 600;
        font-size: 16px;
        line-height: 24px;
        color: #111827;
        display: block;
        margin-bottom: 8px;
    }
    input[type="text"]{
        min-width: 100% !important;
        border: 1px solid #6b7280;
        border-radius: 4px;
        padding: 8px 12px;
        font-size: 16px;
        line-height: 24px;
        color: #111827;
        box-sizing: border-box;
        font-family: 'Inter', sans-serif;
        resize: none;
    }
    input[type="text"]:focus{
        outline: none;
        border-color: #2563eb;
        box-shadow: 0 0 0 2px rgba(37, 99, 235, 0.5);
    }
    button {
        margin-top: 24px;
        font-size: 16px;
        font-weight: 500;
        color: #2563eb;
        background: transparent;
        border: 1px solid #2563eb;
        border-radius: 4px;
        padding: 4px 20px;
        cursor: pointer;
        font-family: 'Inter', sans-serif;
        transition: background-color 0.2s ease;
        display: flex;
    }
    button:hover {
        background-color: #e0e7ff;
    }
    /* Toggle switch */
    .toggle-switch {
        position: relative;
        width: 42px;
        height: 20px;
        display: inline-block;
    }
    .toggle-switch input {
        opacity: 0;
        width: 0;
        height: 0;
        position: absolute;
    }
    .slider {
        position: absolute;
        cursor: pointer;
        top: 0;
        left: 4;
        right: 0;
        bottom: 0;
        background-color: #e5e7eb;
        border-radius: 9999px;
        transition: background-color 0.3s;
    }
    .slider::before {
        position: absolute;
        content: "";
        height: 17px;
        width: 17px;
        left: -6px;
        bottom: 2px;
        background-color: white;
        border-radius: 50%;
        transition: transform 0.3s;
        box-shadow: 0 1px 3px rgb(0 0 0 / 0.1);
    }
    input:checked + .slider {
        background-color: #000304;
    }
    input:checked + .slider::before {
        transform: translateX(24px);
    }
    @media (max-width: 640px) {
    body {
        padding: 16px;
      }
      p {
        max-width: 100%;
      }
    }
  </style>
<div class="fluent-cart-sandbox-connect-manually">
    <form>
        <div class="header">
            <p>Manually Connect sandbox</p>
            <label class="toggle-switch" for="toggle">
                <input type="checkbox" id="toggle" />
                <span class="slider"></span>
            </label>
        </div>
        <div class="fluent-cart-manual-section" style="display: none;">
            <div style="text-align: left;">
                <label for="sandbox-client-id">Sandbox Client ID</label>
                <input style="min-width: 100% !important;height: 32px;" type="text" id="sandbox-client-id" name="sandbox-client-id" />
            </div>
            <div style="margin-top: 24px;text-align: left;">
                <label for="sandbox-secret-key">Sandbox Secret Key</label>
                <input style="min-width: 100% !important;height: 32px;" id="sandbox-secret-key" name="sandbox-secret-key" type="password"></input>
            </div>
            <button type="button">Save Credentials</button>
        </div>
      </form>
</div>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const toggle = document.getElementById('toggle');
    const manualSection = document.querySelector('.fluent-cart-manual-section');
    // Set initial state based on toggle
    manualSection.style.display = toggle.checked ? 'block' : 'none';
    // Add event listener for toggle changes
    toggle.addEventListener('change', function() {
        manualSection.style.display = this.checked ? 'block' : 'none';
    });
});
</script>
