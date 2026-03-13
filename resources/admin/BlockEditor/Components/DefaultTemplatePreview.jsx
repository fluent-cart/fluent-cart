import blocktranslate from "@/BlockEditor/BlockEditorTranslator";

/**
 * Shared inline styles for default template previews.
 * Mirrors the PHP email template parts (emails/parts/*).
 */
const S = {
    table: {borderSpacing: 0, padding: 0, width: '100%', border: 'none'},
    thRow: {backgroundColor: '#f9fafb'},
    th: {fontSize: '12px', fontWeight: 600, color: '#374151', textTransform: 'uppercase', lineHeight: '24px', margin: 0, textAlign: 'left'},
    thRight: {fontSize: '12px', fontWeight: 600, color: '#374151', textTransform: 'uppercase', lineHeight: '24px', margin: 0, textAlign: 'right'},
    itemName: {fontSize: '15px', color: '#2F3448', fontWeight: 500, lineHeight: '18px', margin: '0 0 5px'},
    itemSub: {margin: 0, fontSize: '14px', color: '#758195', fontWeight: 400, lineHeight: '15px'},
    itemPrice: {fontSize: '14px', fontWeight: 700, color: '#111827', margin: 0, lineHeight: '24px', textAlign: 'right'},
    summaryBox: {backgroundColor: '#f9fafb', padding: '16px', borderRadius: '8px', margin: 0, width: '100%', border: 'none'},
    summaryLabel: {fontSize: '14px', color: '#374151', lineHeight: '24px', margin: 0},
    summaryValue: {fontSize: '14px', color: '#374151', margin: 0, lineHeight: '24px', textAlign: 'right'},
    totalLabel: {fontSize: '16px', fontWeight: 700, color: '#111827', lineHeight: '24px', margin: 0},
    totalValue: {fontSize: '14px', fontWeight: 700, color: '#111827', lineHeight: '24px', margin: 0, textAlign: 'right'},
    addressHeading: {fontSize: '12px', color: '#7f8c8d', margin: '0 0 8px', textTransform: 'uppercase', letterSpacing: '1px', lineHeight: '24px'},
    addressText: {fontSize: '14px', color: '#2c3e50', margin: 0, lineHeight: 1.4},
    noticeBox: {backgroundColor: '#eff6ff', padding: '12px', borderRadius: '6px', marginBottom: '20px', border: '1px solid #bfdbfe'},
    noticeTitle: {fontSize: '14px', fontWeight: 600, color: '#1e3a8a', lineHeight: '24px', margin: '0 0 6px'},
    noticeText: {fontSize: '13px', color: '#1e40af', marginBottom: 0, lineHeight: 1.2, marginTop: 0},
    badge: {
        display: 'inline-block', fontSize: '10px', fontWeight: 600, textTransform: 'uppercase',
        letterSpacing: '0.05em', padding: '2px 8px', borderRadius: '4px',
        backgroundColor: '#dbeafe', color: '#1e40af', marginLeft: '6px',
    },
    wrapper: {position: 'relative', opacity: 0.75, pointerEvents: 'none'},
};

/**
 * Order Items preview — matches emails/parts/items_table.php
 */
export const OrderItemsPreview = () => (
    <div style={S.wrapper}>
        <table style={S.table}>
            <thead>
            <tr>
                <th style={{...S.thRow, paddingLeft: '16px'}}>
                    <p style={S.th}>{blocktranslate('Item')}</p>
                </th>
                <th style={{...S.thRow, width: '50px'}}></th>
                <th style={{...S.thRow, paddingRight: '16px', width: '200px'}}>
                    <p style={S.thRight}>{blocktranslate('Total')}</p>
                </th>
            </tr>
            </thead>
            <tbody>
            <tr>
                <td style={{paddingLeft: '16px', paddingTop: '8px', paddingBottom: '8px'}} colSpan="2">
                    <p style={S.itemName}>
                        Sample Product
                        <span style={{fontSize: '12px', fontWeight: 400, color: '#4b5563'}}> x 1</span>
                    </p>
                    <p style={S.itemSub}>- Standard License</p>
                </td>
                <td style={{paddingRight: '16px', textAlign: 'right'}}>
                    <p style={S.itemPrice}>$49.00</p>
                </td>
            </tr>
            <tr>
                <td style={{paddingLeft: '16px', paddingTop: '8px', paddingBottom: '8px'}} colSpan="2">
                    <p style={S.itemName}>Another Product</p>
                    <p style={S.itemSub}>- Pro Plan</p>
                    <p style={{fontSize: '12px', color: '#4b5563', lineHeight: '20px', margin: '3px 0 0'}}>$29/month</p>
                </td>
                <td style={{paddingRight: '16px', textAlign: 'right'}}>
                    <p style={S.itemPrice}>$29.00</p>
                </td>
            </tr>
            </tbody>
        </table>
        <table style={{...S.table, marginTop: '8px'}}>
            <tbody>
            <tr>
                <td style={{width: '50%'}}></td>
                <td style={{width: '100%'}}>
                    <table style={S.summaryBox}>
                        <tbody>
                        <tr>
                            <td style={{width: '70%'}}><p style={S.summaryLabel}>{blocktranslate('Subtotal')}</p></td>
                            <td style={{width: '30%', textAlign: 'right'}}><p style={S.summaryValue}>$78.00</p></td>
                        </tr>
                        <tr>
                            <td style={{width: '70%'}}><p style={S.summaryLabel}>{blocktranslate('Shipping')}</p></td>
                            <td style={{width: '30%', textAlign: 'right'}}><p style={S.summaryValue}>$5.00</p></td>
                        </tr>
                        <tr>
                            <td style={{width: '70%'}}><p style={S.summaryLabel}>{blocktranslate('Discount')}</p></td>
                            <td style={{width: '30%', textAlign: 'right'}}><p style={{...S.summaryValue, color: '#16a34a'}}>-$10.00</p></td>
                        </tr>
                        <tr>
                            <td style={{width: '70%'}}><p style={S.summaryLabel}>{blocktranslate('Tax')}</p></td>
                            <td style={{width: '30%', textAlign: 'right'}}><p style={S.summaryValue}>$6.24</p></td>
                        </tr>
                        <tr>
                            <td colSpan="2"><hr style={{borderColor: '#d1d5db', margin: '8px 0', border: 'none', borderTop: '1px solid #eaeaea'}}/></td>
                        </tr>
                        <tr>
                            <td style={{width: '70%'}}><p style={S.totalLabel}>{blocktranslate('Total')}</p></td>
                            <td style={{width: '30%', textAlign: 'right'}}><p style={S.totalValue}>$79.24</p></td>
                        </tr>
                        <tr>
                            <td style={{width: '70%'}}><p style={S.summaryLabel}>{blocktranslate('Payment Method')}</p></td>
                            <td style={{width: '30%', textAlign: 'right'}}><p style={{...S.summaryValue, textTransform: 'uppercase', fontSize: '13px'}}>STRIPE</p></td>
                        </tr>
                        </tbody>
                    </table>
                </td>
            </tr>
            </tbody>
        </table>
    </div>
);

/**
 * License Details preview — matches emails/parts/licenses.php
 */
export const LicenseDetailsPreview = () => (
    <div style={S.wrapper}>
        <table style={{...S.table, padding: '0 0 0 10px'}}>
            <tbody>
            <tr>
                <td style={{border: 'none', padding: 0}}>
                    <table style={{...S.table, marginBottom: '10px'}}>
                        <tbody>
                        <tr>
                            <td style={{width: '100%', border: 'none', padding: '4px 0'}}>
                                <span style={{fontSize: '15px', color: '#2F3448', fontWeight: 500, lineHeight: '18px'}}>
                                    Standard License: XXXX-XXXX-XXXX-XXXX
                                </span>
                                <br/>
                                <span style={{fontSize: '12px', color: '#758195', lineHeight: '18px'}}>
                                    {blocktranslate('Activations')}: 0/5 &nbsp;|&nbsp; {blocktranslate('Expires')}: Dec 31, 2026
                                </span>
                            </td>
                        </tr>
                        <tr>
                            <td style={{width: '100%', border: 'none', padding: '4px 0'}}>
                                <span style={{fontSize: '15px', color: '#2F3448', fontWeight: 500, lineHeight: '18px'}}>
                                    Pro License: YYYY-YYYY-YYYY-YYYY
                                </span>
                                <br/>
                                <span style={{fontSize: '12px', color: '#758195', lineHeight: '18px'}}>
                                    {blocktranslate('Activations')}: 0/10 &nbsp;|&nbsp; {blocktranslate('Expires')}: Dec 31, 2026
                                </span>
                            </td>
                        </tr>
                        </tbody>
                    </table>
                </td>
            </tr>
            </tbody>
        </table>
        <table style={S.noticeBox}>
            <tbody>
            <tr>
                <td>
                    <p style={S.noticeTitle}>{blocktranslate('Important')}</p>
                    <p style={S.noticeText}>{blocktranslate('This download link is valid for 7 days. After that, you can download the files again from your account on our website.')}</p>
                </td>
            </tr>
            </tbody>
        </table>
    </div>
);

/**
 * Download Details preview — matches emails/parts/downloads.php
 */
export const DownloadDetailsPreview = () => (
    <div style={S.wrapper}>
        <table style={{...S.table, padding: '0 0 0 10px'}}>
            <tbody>
            <tr>
                <td style={{border: 'none', padding: 0}}>
                    <table style={{...S.table, marginBottom: '10px'}}>
                        <tbody>
                        <tr>
                            <td style={{width: '100%', border: 'none', padding: '1px 0', verticalAlign: 'middle'}}>
                                <p style={{margin: 0, fontSize: '14px'}}>
                                    plugin-v2.0.zip
                                    <span style={{color: '#666', fontSize: '12px'}}> (4.2 MB)</span>
                                </p>
                            </td>
                            <td style={{width: '20%', textAlign: 'right', verticalAlign: 'middle', border: 'none', padding: '1px 0'}}>
                                <span style={{display: 'inline-block', fontSize: '14px', color: '#000', lineHeight: 1}}>
                                    {blocktranslate('Download')}
                                </span>
                            </td>
                        </tr>
                        <tr>
                            <td style={{width: '100%', border: 'none', padding: '1px 0', verticalAlign: 'middle'}}>
                                <p style={{margin: 0, fontSize: '14px'}}>
                                    documentation.pdf
                                    <span style={{color: '#666', fontSize: '12px'}}> (1.1 MB)</span>
                                </p>
                            </td>
                            <td style={{width: '20%', textAlign: 'right', verticalAlign: 'middle', border: 'none', padding: '1px 0'}}>
                                <span style={{display: 'inline-block', fontSize: '14px', color: '#000', lineHeight: 1}}>
                                    {blocktranslate('Download')}
                                </span>
                            </td>
                        </tr>
                        </tbody>
                    </table>
                </td>
            </tr>
            </tbody>
        </table>
        <table style={S.noticeBox}>
            <tbody>
            <tr>
                <td>
                    <p style={S.noticeTitle}>{blocktranslate('Important')}</p>
                    <p style={S.noticeText}>{blocktranslate('This download link is valid for 7 days. After that, you can download the files again from your account on our website.')}</p>
                </td>
            </tr>
            </tbody>
        </table>
    </div>
);

/**
 * Subscription Details preview — matches emails/parts/subscription_item.php
 */
export const SubscriptionDetailsPreview = () => (
    <div style={S.wrapper}>
        <table style={{...S.table, border: '1px solid #e5e7eb', borderRadius: '8px', overflow: 'hidden', marginBottom: '16px'}}>
            <tbody>
            <tr>
                <td>
                    <table style={{...S.table, backgroundColor: '#f9fafb', paddingLeft: '16px', paddingRight: '16px', borderBottom: '1px solid #e5e7eb'}}>
                        <tbody>
                        <tr>
                            <td style={{width: '80%'}}>
                                <p style={{...S.th, margin: 0}}>{blocktranslate('Subscription')}</p>
                            </td>
                            <td style={{width: '20%', textAlign: 'right'}}>
                                <p style={{...S.thRight, margin: 0}}>{blocktranslate('Price')}</p>
                            </td>
                        </tr>
                        </tbody>
                    </table>
                    <table style={{...S.table, paddingLeft: '16px', paddingRight: '16px'}}>
                        <tbody>
                        <tr>
                            <td style={{width: '80%'}}>
                                <p style={{fontSize: '14px', fontWeight: 600, color: '#111827', marginBottom: '2px', lineHeight: '24px', marginTop: '16px'}}>
                                    Pro Plan
                                </p>
                            </td>
                            <td style={{width: '20%', textAlign: 'right'}}>
                                <p style={{fontSize: '14px', color: '#111827', margin: 0, lineHeight: '24px'}}>
                                    $29.00 (Monthly)
                                </p>
                            </td>
                        </tr>
                        </tbody>
                    </table>
                </td>
            </tr>
            </tbody>
        </table>
        <table style={S.summaryBox}>
            <tbody>
            <tr>
                <td style={{width: '70%'}}><p style={S.summaryLabel}>{blocktranslate('Subtotal')}</p></td>
                <td style={{width: '30%', textAlign: 'right'}}><p style={S.summaryValue}>$29.00</p></td>
            </tr>
            <tr>
                <td style={{width: '70%'}}><p style={S.summaryLabel}>{blocktranslate('Tax')}</p></td>
                <td style={{width: '30%', textAlign: 'right'}}><p style={S.summaryValue}>$2.32</p></td>
            </tr>
            <tr>
                <td colSpan="2"><hr style={{borderColor: '#d1d5db', margin: '8px 0', border: 'none', borderTop: '1px solid #eaeaea'}}/></td>
            </tr>
            <tr>
                <td style={{width: '70%'}}><p style={S.totalLabel}>{blocktranslate('Total')}</p></td>
                <td style={{width: '30%', textAlign: 'right'}}><p style={S.totalValue}>$31.32</p></td>
            </tr>
            <tr>
                <td colSpan="2"><hr style={{borderColor: '#d1d5db', margin: '8px 0', border: 'none', borderTop: '1px solid #eaeaea'}}/></td>
            </tr>
            <tr>
                <td style={{width: '70%'}}><p style={S.summaryLabel}>{blocktranslate('Payment Method')}</p></td>
                <td style={{width: '30%', textAlign: 'right'}}><p style={S.summaryValue}>Stripe</p></td>
            </tr>
            </tbody>
        </table>
    </div>
);

/**
 * Order Addresses preview — matches emails/parts/addresses.php
 */
export const OrderAddressesPreview = () => (
    <div style={S.wrapper}>
        <table style={{...S.table, marginBottom: '24px'}}>
            <tbody style={{verticalAlign: 'top'}}>
            <tr>
                <td>
                    <table style={S.table}>
                        <tbody style={{width: '100%', verticalAlign: 'top'}}>
                        <tr>
                            <td style={{width: '50%', paddingLeft: 0, paddingRight: '10px'}}>
                                <p style={S.addressHeading}>{blocktranslate('Billing Address')}</p>
                                <p style={S.addressText}>
                                    John Doe<br/>
                                    Acme Inc.<br/>
                                    123 Main Street<br/>
                                    New York, NY 10001<br/>
                                    United States<br/>
                                    <span style={{color: '#758195', fontSize: '13px'}}>{blocktranslate('Phone')}: (555) 123-4567</span><br/>
                                    <span style={{color: '#758195', fontSize: '13px'}}>{blocktranslate('Email')}: john@example.com</span>
                                </p>
                            </td>
                            <td style={{width: '50%', paddingRight: 0, paddingLeft: '10px'}}>
                                <p style={S.addressHeading}>{blocktranslate('Shipping Address')}</p>
                                <p style={S.addressText}>
                                    John Doe<br/>
                                    456 Oak Avenue<br/>
                                    Los Angeles, CA 90001<br/>
                                    United States<br/>
                                    <span style={{color: '#758195', fontSize: '13px'}}>{blocktranslate('Phone')}: (555) 987-6543</span>
                                </p>
                            </td>
                        </tr>
                        </tbody>
                    </table>
                </td>
            </tr>
            </tbody>
        </table>
    </div>
);
