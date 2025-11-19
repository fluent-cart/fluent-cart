document.getElementById('print-button').addEventListener('click', () => {
    let invoiceNo = "FluentCart Receipt";
    if (invoiceNo) {
        if (document.getElementById('fct-order-invoice-no')) {
            invoiceNo = "Receipt Number: " + document.getElementById('fct-order-invoice-no').innerText
        }
    }
    let fcEmailTemplateWrap = document.querySelector('.fct-email-template-content');
    let fcEmailTemplateWrapInner = document.querySelector('.fct-email-template-content-inner');
    printThis(document.getElementById('receipt'), {
        debug: false,
        importCSS: true,
        printContainer: true,
        pageTitle: invoiceNo,
        removeInline: false,
        printDelay: 100,
        beforePrint: function () {
            console.log('About to print...');
        },
        afterPrint: function () {
            console.log('Print completed!');
        }
    });
});

window.addEventListener('DOMContentLoaded', function () {
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('download') === '1') {
        const printButton = document.getElementById('print-button');
        if (printButton) {
            printButton.click();
        }
    }
});
