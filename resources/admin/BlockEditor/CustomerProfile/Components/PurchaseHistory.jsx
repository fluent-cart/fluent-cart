import blocktranslate from "@/BlockEditor/BlockEditorTranslator";

const PurchaseHistory = () => {
    return (
        <table>
            <colgroup>
                <col style={{ width: '10%' }} />
                <col style={{ width: '50%' }} />
                <col style={{ width: '20%' }} />
                <col style={{ width: '10%' }} />
            </colgroup>
            <tbody>
                <tr>
                    <td>
                        <span className="invoice-id">
                            #INV-6
                        </span>
                        <span className="text">Aug 13</span>
                    </td>
                    <td>
                        <div className="product-info">
                            <div className="title">
                                {blocktranslate('Fluent Forms Pro Add-On: The Fastest & Most Powerful WordPress Form Plugin')}
                                <span> – {blocktranslate('Single Site Lifetime License')}</span>
                            </div>
                        </div>
                    </td>
                    <td><span className="badge success">{blocktranslate('Completed')}</span></td>
                    <td>$49.00</td>
                </tr>
                <tr>
                    <td>
                        <span className="text-system-dark text-sm block">
                            #INV-7
                        </span>
                        <span className="text">Aug 14</span>
                    </td>
                    <td>
                        <div className="product-info">
                            <div className="title">
                                {blocktranslate('Fluent Forms Pro Add-On: The Fastest & Most Powerful WordPress Form Plugin')}
                                <span> – {blocktranslate('Single Site Lifetime License')}</span>
                            </div>
                        </div>
                    </td>
                    <td><span className="badge warning">{blocktranslate('Processing')}</span></td>
                    <td>$149.00</td>
                </tr>
            </tbody>
        </table>
    );
};

export default PurchaseHistory;
