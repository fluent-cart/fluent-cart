import {CustomerDashboard} from "@/BlockEditor/Icons";
import blocktranslate from "@/BlockEditor/BlockEditorTranslator";
// import InspectorSettings from "./Components/InspectorSettings";
import DashboardSection from "./Components/DashboardSection";
import PurchaseHistory from "./Components/PurchaseHistory";
import colorConfig from "@/BlockEditor/CustomerProfile/colorConfig";
import CustomerProfilePreview from "./CustomerProfile.png";


const { useBlockProps } = wp.blockEditor;
const {registerBlockType} = wp.blocks;

const blockEditorData = window.fluent_cart_customer_profile_data;


registerBlockType(blockEditorData.slug + '/' + blockEditorData.name, {
    title: blockEditorData.title,
    category: "fluent-cart",
    example: {
        attributes: {},
        innerBlocks: [
            {
                name: 'core/image',
                attributes: {
                    url: CustomerProfilePreview,
                    alt: 'Customer Profile Preview',
                    style: {
                        width: '100%',
                        height: 'auto',
                    }
                }
            },
        ],
    },
    icon: {
        src: CustomerDashboard,
    },
    attributes: {
        sectionTitles: {
            type: 'object',
            default: {
                purchaseHistory: blocktranslate('Purchase History'),
                subscriptions: blocktranslate('Subscription Plans'),
                licenses: blocktranslate('Licenses'),
                downloads: blocktranslate('Downloads')
            }
        },
        colors: {
            type: 'object',
            default: colorConfig
        },
    },
    edit: ({ attributes, setAttributes }) => {
        const blockProps = useBlockProps();
        const { sectionTitles, colors } = attributes;

        const styleVars = {};
        Object.entries(colors).forEach(([sectionKey, config]) => {
            Object.entries(config).forEach(([cssVar, { value, type }]) => {
                if (type === 'box') {
                    const { top = '0', right = '0', bottom = '0', left = '0' } = value;
                    styleVars[cssVar] = `${top} ${right} ${bottom} ${left}`;
                } else {
                    styleVars[cssVar] = value;
                }
            });
        });

        const updateTitle = (key, value) => {
            console.log(value)
            setAttributes({
                sectionTitles: {
                    ...sectionTitles,
                    [key]: value
                }
            });
        };

        return (
            <>
                <div {...blockProps}>
                    {/* Inspector Sidebar */}
                    {/*<InspectorSettings*/}
                    {/*    attributes={attributes}*/}
                    {/*    setAttributes={setAttributes}*/}
                    {/*/>*/}

                    {/* Main Block Content */}
                    <div className="fct-customer-profile-block-editor" style={styleVars}>
                        <div className="fct-customer-dashboard-app-container fluent-cart-customer-profile-app">
                            <div className="fct-customer-dashboard-navs-wrap">
                                <div className="fct-customer-dashboard-customer-info">
                                    <img decoding="async"
                                         src="https://placehold.co/400"
                                         alt="Kamran&nbsp;Hussen"/>
                                    <div className="fct-customer-dashboard-customer-info-content">
                                        <h3>{blocktranslate('Alex Smith')}</h3>
                                        <p>alexsmith@gmail.com</p>
                                    </div>
                                    <div id="fct-customer-logout-button">
                                        <a href="#"
                                           title={blocktranslate('Logout')} className="fct-customer-logout-btn">
                                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"
                                                 fill="currentColor">
                                                <path
                                                    d="M5 22C4.44772 22 4 21.5523 4 21V3C4 2.44772 4.44772 2 5 2H19C19.5523 2 20 2.44772 20 3V6H18V4H6V20H18V18H20V21C20 21.5523 19.5523 22 19 22H5ZM18 16V13H11V11H18V8L23 12L18 16Z"></path>
                                            </svg>
                                        </a>
                                    </div>
                                </div>
                                <div id="menu-container">
                                    <div id="fct-customer-menu-holder">
                                        <div className="fct-customer-navs-wrap">
                                            <ul className="fct-customer-navs">
                                                <li className="fct-customer-nav-item fct-customer-nav-item-dashboard active_customer_menu">
                                                    <a className="fct-customer-nav-link fct_route" aria-label={blocktranslate('Dashboard')}
                                                       href="#">
                                                        {blocktranslate('Dashboard')} </a>
                                                </li>
                                                <li className="fct-customer-nav-item fct-customer-nav-item-purchase-history">
                                                    <a className="fct-customer-nav-link fct_route"
                                                       aria-label={blocktranslate('Purchase History')}
                                                       href="#">
                                                        {blocktranslate('Purchase History')} </a>
                                                </li>
                                                <li className="fct-customer-nav-item fct-customer-nav-item-subscriptions">
                                                    <a className="fct-customer-nav-link fct_route"
                                                       aria-label={blocktranslate('Subscription Plans')}
                                                       href="#">
                                                        {blocktranslate('Subscription Plans')} </a>
                                                </li>
                                                <li className="fct-customer-nav-item fct-customer-nav-item-licenses">
                                                    <a className="fct-customer-nav-link fct_route" aria-label={blocktranslate('Licenses')}
                                                       href="#">
                                                        {blocktranslate('Licenses')} </a>
                                                </li>
                                                <li className="fct-customer-nav-item fct-customer-nav-item-downloads">
                                                    <a className="fct-customer-nav-link fct_route" aria-label={blocktranslate('Downloads')}
                                                       href="#">
                                                        {blocktranslate('Downloads')} </a>
                                                </li>
                                                <li className="fct-customer-nav-item fct-customer-nav-item-profile">
                                                    <a className="fct-customer-nav-link fct_route" aria-label={blocktranslate('Profile')}
                                                       href="#">
                                                        {blocktranslate('Profile')} </a>
                                                </li>
                                            </ul>
                                        </div>
                                    </div>
                                </div>
                                <div id="fct-customer-menu-toggle">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M3 4H21V6H3V4ZM3 11H21V13H3V11ZM3 18H21V20H3V18Z"></path></svg>
                                </div>
                            </div>

                            <div className="fct-customer-dashboard-main-content">
                                {/* Purchase History Section */}
                                <DashboardSection
                                    title={blocktranslate('Purchase History')}
                                    value={sectionTitles.purchaseHistory}
                                    onChange={(value) => updateTitle('purchaseHistory', value)}
                                >
                                    <PurchaseHistory/>
                                </DashboardSection>
                            </div>
                        </div>

                    </div>
                </div>
            </>
        );
    },
    save: () => null
});
