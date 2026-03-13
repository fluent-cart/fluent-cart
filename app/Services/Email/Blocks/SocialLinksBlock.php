<?php

namespace FluentCart\App\Services\Email\Blocks;

/**
 * Social Links Block Renderer for Email
 *
 * Converts Gutenberg core/social-links blocks to email-compatible HTML.
 *
 * @package FluentCart\App\Services\Email\Blocks
 */
class SocialLinksBlock extends BaseBlock
{
    /**
     * Brand colors for social services
     *
     * @var array
     */
    protected $brandColors = [
        'facebook'  => '#1877f2',
        'twitter'   => '#1da1f2',
        'x'         => '#000000',
        'linkedin'  => '#0077b5',
        'instagram' => '#e4405f',
        'github'    => '#181717',
        'wordpress' => '#21759b',
        'amazon'    => '#ff9900',
        'youtube'   => '#ff0000',
        'tiktok'    => '#000000',
        'pinterest' => '#bd081c',
        'telegram'  => '#26a5e4',
        'whatsapp'  => '#25d366',
        'discord'   => '#5865f2',
        'slack'     => '#4a154b',
        'dribbble'  => '#ea4c89',
        'behance'   => '#1769ff',
        'medium'    => '#000000',
        'reddit'    => '#ff4500',
        'twitch'    => '#9146ff',
        'spotify'   => '#1db954',
        'link'      => '#0073aa',
    ];

    /**
     * SVG icons for social services
     *
     * @var array
     */
    protected $icons = [
        'facebook'  => '<svg width="24" height="24" viewBox="0 0 24 24" fill="white" xmlns="http://www.w3.org/2000/svg"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>',
        'twitter'   => '<svg width="24" height="24" viewBox="0 0 24 24" fill="white" xmlns="http://www.w3.org/2000/svg"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>',
        'x'         => '<svg width="24" height="24" viewBox="0 0 24 24" fill="white" xmlns="http://www.w3.org/2000/svg"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>',
        'linkedin'  => '<svg width="24" height="24" viewBox="0 0 24 24" fill="white" xmlns="http://www.w3.org/2000/svg"><path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433c-1.144 0-2.063-.926-2.063-2.065 0-1.138.92-2.063 2.063-2.063 1.14 0 2.064.925 2.064 2.063 0 1.139-.925 2.065-2.064 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z"/></svg>',
        'instagram' => '<svg width="24" height="24" viewBox="0 0 24 24" fill="white" xmlns="http://www.w3.org/2000/svg"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zM12 0C8.741 0 8.333.014 7.053.072 2.695.272.273 2.69.073 7.052.014 8.333 0 8.741 0 12c0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98C8.333 23.986 8.741 24 12 24c3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98C15.668.014 15.259 0 12 0zm0 5.838a6.162 6.162 0 100 12.324 6.162 6.162 0 000-12.324zM12 16a4 4 0 110-8 4 4 0 010 8zm6.406-11.845a1.44 1.44 0 100 2.881 1.44 1.44 0 000-2.881z"/></svg>',
        'github'    => '<svg width="24" height="24" viewBox="0 0 24 24" fill="white" xmlns="http://www.w3.org/2000/svg"><path d="M12 .297c-6.63 0-12 5.373-12 12 0 5.303 3.438 9.8 8.205 11.385.6.113.82-.258.82-.577 0-.285-.01-1.04-.015-2.04-3.338.724-4.042-1.61-4.042-1.61C4.422 18.07 3.633 17.7 3.633 17.7c-1.087-.744.084-.729.084-.729 1.205.084 1.838 1.236 1.838 1.236 1.07 1.835 2.809 1.305 3.495.998.108-.776.417-1.305.76-1.605-2.665-.3-5.466-1.332-5.466-5.93 0-1.31.465-2.38 1.235-3.22-.135-.303-.54-1.523.105-3.176 0 0 1.005-.322 3.3 1.23.96-.267 1.98-.399 3-.405 1.02.006 2.04.138 3 .405 2.28-1.552 3.285-1.23 3.285-1.23.645 1.653.24 2.873.12 3.176.765.84 1.23 1.91 1.23 3.22 0 4.61-2.805 5.625-5.475 5.92.42.36.81 1.096.81 2.22 0 1.606-.015 2.896-.015 3.286 0 .315.21.69.825.57C20.565 22.092 24 17.592 24 12.297c0-6.627-5.373-12-12-12"/></svg>',
        'wordpress' => '<svg width="24" height="24" viewBox="0 0 24 24" fill="white" xmlns="http://www.w3.org/2000/svg"><path d="M21.469 6.825c.84 1.537 1.318 3.3 1.318 5.175 0 3.979-2.156 7.456-5.363 9.325l3.295-9.527c.615-1.54.82-2.771.82-3.864 0-.405-.026-.78-.07-1.11zm-7.981.105c.647-.03 1.232-.105 1.232-.105.582-.075.514-.93-.067-.899 0 0-1.755.135-2.88.135-1.064 0-2.85-.15-2.85-.15-.585-.03-.661.855-.075.885 0 0 .54.061 1.125.09l1.68 4.605-2.37 7.08L5.354 6.9c.649-.03 1.234-.1 1.234-.1.585-.075.516-.93-.065-.896 0 0-1.746.138-2.874.138-.2 0-.438-.008-.69-.015C4.911 3.15 8.235 1.215 12 1.215c2.809 0 5.365 1.072 7.286 2.833-.046-.003-.091-.009-.141-.009-1.06 0-1.812.923-1.812 1.914 0 .89.513 1.643 1.06 2.531.411.72.89 1.643.89 2.977 0 .915-.354 1.994-.821 3.479l-1.075 3.585-3.9-11.61.001.014zM12 22.784c-1.059 0-2.081-.153-3.048-.437l3.237-9.406 3.315 9.087c.024.053.05.101.078.149-1.12.393-2.325.607-3.582.607zM1.211 12c0-1.564.336-3.05.935-4.39L7.29 21.709C3.694 19.96 1.212 16.271 1.212 12zm10.785-10.784C6.596 1.215 1.214 6.597 1.214 12s5.382 10.785 10.784 10.785S22.784 17.403 22.784 12 17.402 1.215 11.996 1.215z"/></svg>',
        'amazon'    => '<svg width="24" height="24" viewBox="0 0 24 24" fill="white" xmlns="http://www.w3.org/2000/svg"><path d="M.045 18.02c.072-.116.187-.124.348-.022 3.636 2.11 7.594 3.166 11.87 3.166 2.852 0 5.668-.533 8.447-1.595l.315-.14c.138-.06.234-.1.293-.13.226-.088.39-.046.493.13.116.19.088.347-.084.47-.104.073-.207.146-.31.22-.295.211-.59.416-.884.615-.543.352-1.086.672-1.63.957-.903.453-1.836.846-2.797 1.18-.96.332-1.926.596-2.898.79-.972.19-1.948.29-2.93.29-1.64 0-3.2-.225-4.682-.675-1.483-.45-2.868-1.09-4.153-1.92-.285-.18-.557-.372-.817-.573C.253 20.64.107 20.478.02 20.26c-.05-.13-.05-.26.002-.388.05-.127.132-.233.246-.318.11-.082.232-.148.362-.198l.164-.06c.243-.085.462-.165.66-.24.295-.112.584-.227.868-.344l.242-.1c.168-.068.334-.135.5-.2.17-.066.343-.13.518-.193.15-.052.3-.103.45-.153.147-.05.294-.098.44-.145l.152-.048c.196-.066.39-.127.58-.186.192-.056.385-.11.58-.16l.16-.04c.12-.03.24-.06.362-.088l.16-.04c.16-.04.32-.077.478-.113.157-.035.314-.07.472-.104l.272-.055c.093-.018.186-.036.28-.054l.175-.03c.123-.02.246-.04.37-.058l.232-.033c.3-.044.598-.082.895-.115.297-.032.592-.058.885-.078l.06-.005c.166-.012.332-.02.497-.025.166-.005.332-.007.498-.007 1.188 0 2.36.108 3.512.325 1.152.216 2.27.527 3.358.935.09.034.18.07.27.106.087.034.174.07.26.106l.137.058c.065.027.13.054.194.08.082.034.165.07.247.104.264.11.526.225.787.34.156.07.312.14.467.21.237.108.472.22.706.332.167.08.333.16.498.24.115.054.228.11.34.165.172.085.343.17.513.256.136.07.272.138.407.208l.287.148c.163.085.326.17.488.256.188.1.375.2.56.302l.238.132c.136.076.27.152.404.228l.232.133c.136.078.27.156.404.235l.26.152c.242.142.482.284.72.43l.2.12c.196.117.39.235.582.356l.17.105c.188.116.374.234.558.355l.153.098c.18.116.358.233.535.352l.14.093c.178.12.355.24.53.362l.126.087c.178.124.354.248.528.375l.11.08c.178.13.354.26.528.392l.092.07c.18.138.356.275.53.414l.07.055c.186.148.368.296.548.447l.04.033c.198.166.392.334.582.503.19.17.377.34.56.514l.073.07c.163.157.323.317.48.477.158.162.313.326.465.49l.068.075c.142.157.282.317.418.478.137.162.27.326.402.49l.068.086c.127.16.25.323.37.486.122.164.24.33.357.496l.07.1c.113.163.223.328.33.494.108.167.213.336.316.504l.066.108c.103.172.203.345.3.52.097.174.191.35.283.527l.057.11c.098.19.19.38.28.573.09.192.176.386.26.58l.04.097c.09.208.175.417.256.628.08.21.158.423.232.636l.03.087c.083.24.16.48.234.723.073.243.142.488.207.733l.02.075c.073.276.14.553.2.832.062.28.118.56.168.843l.008.05c.06.32.11.642.154.965.044.324.08.65.108.976l.004.05c.034.363.06.728.076 1.093.017.366.025.733.025 1.1 0 .37-.008.738-.025 1.104-.017.366-.042.73-.076 1.095l-.004.05c-.028.327-.064.652-.108.976-.044.323-.095.645-.155.965l-.007.05c-.05.283-.106.564-.168.843-.06.28-.127.557-.2.832l-.02.076c-.065.245-.134.49-.207.733-.074.242-.15.482-.234.72l-.03.09c-.074.213-.152.425-.232.635-.08.21-.166.42-.256.628l-.04.097c-.084.194-.17.388-.26.58-.09.193-.182.384-.28.573l-.057.11c-.092.177-.186.353-.283.528-.097.174-.197.347-.3.52l-.066.107c-.103.17-.208.337-.316.504-.107.166-.217.33-.33.494l-.07.1c-.117.166-.235.332-.357.496-.12.163-.243.326-.37.486l-.068.086c-.132.165-.265.328-.402.49-.136.16-.276.32-.418.478l-.068.075c-.152.165-.307.328-.465.49-.157.16-.317.32-.48.477l-.073.07c-.183.174-.37.345-.56.513-.19.17-.384.337-.582.503l-.04.034c-.18.15-.362.3-.548.446l-.07.055c-.174.132-.35.262-.528.39l-.092.07c-.178.138-.354.277-.53.415l-.11.08c-.174.126-.35.252-.528.375l-.126.087c-.175.122-.352.243-.53.362l-.14.093c-.176.12-.356.24-.535.353l-.153.098c-.184.12-.37.24-.558.355l-.17.104c-.192.12-.386.24-.582.357l-.2.12c-.238.145-.478.288-.72.43l-.26.15c-.134.08-.268.158-.404.236l-.232.133c-.134.076-.268.152-.404.227l-.238.133c-.185.102-.372.202-.56.302-.162.086-.325.17-.488.256l-.287.147c-.135.07-.27.14-.407.208-.17.087-.34.172-.513.257-.112.055-.225.11-.34.164-.165.08-.33.16-.498.24-.234.112-.47.223-.706.332-.155.07-.31.14-.467.21-.26.115-.523.23-.787.34-.082.034-.165.07-.247.105-.064.026-.13.053-.194.08l-.137.056c-.086.037-.173.073-.26.107-.09.036-.18.072-.27.106-1.088.408-2.206.72-3.358.936-1.153.216-2.324.324-3.513.324-.166 0-.332-.002-.498-.006-.165-.005-.33-.013-.497-.025l-.06-.005c-.293-.02-.588-.046-.885-.078-.297-.033-.595-.07-.895-.115l-.232-.033c-.124-.018-.247-.038-.37-.06l-.175-.03c-.094-.017-.187-.035-.28-.053l-.272-.055c-.158-.034-.315-.07-.472-.104-.158-.036-.318-.074-.478-.114l-.16-.04c-.122-.028-.242-.058-.362-.088l-.16-.04c-.195-.05-.388-.104-.58-.16-.19-.06-.384-.12-.58-.186l-.152-.05c-.146-.046-.293-.095-.44-.144-.15-.05-.3-.1-.45-.153-.175-.063-.348-.127-.518-.193-.166-.065-.332-.132-.5-.2l-.242-.1c-.284-.117-.573-.232-.868-.344-.198-.075-.417-.155-.66-.24l-.164-.06c-.13-.05-.252-.116-.362-.198-.114-.085-.196-.19-.246-.318-.052-.128-.052-.258-.002-.388.087-.218.233-.38.445-.49.26-.18.532-.373.817-.573 1.285-.83 2.67-1.47 4.153-1.92 1.482-.45 3.042-.675 4.682-.675.98 0 1.958.098 2.93.29.972.194 1.938.458 2.898.79.96.334 1.894.727 2.798 1.18.543.285 1.086.605 1.63.957.293.2.588.404.883.615.103.074.206.147.31.22.172.123.2.28.084.47"/></svg>',
        'youtube'   => '<svg width="24" height="24" viewBox="0 0 24 24" fill="white" xmlns="http://www.w3.org/2000/svg"><path d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/></svg>',
        'tiktok'    => '<svg width="24" height="24" viewBox="0 0 24 24" fill="white" xmlns="http://www.w3.org/2000/svg"><path d="M12.525.02c1.31-.02 2.61-.01 3.91-.02.08 1.53.63 3.09 1.75 4.17 1.12 1.11 2.7 1.62 4.24 1.79v4.03c-1.44-.05-2.89-.35-4.2-.97-.57-.26-1.1-.59-1.62-.93-.01 2.92.01 5.84-.02 8.75-.08 1.4-.54 2.79-1.35 3.94-1.31 1.92-3.58 3.17-5.91 3.21-1.43.08-2.86-.31-4.08-1.03-2.02-1.19-3.44-3.37-3.65-5.71-.02-.5-.03-1-.01-1.49.18-1.9 1.12-3.72 2.58-4.96 1.66-1.44 3.98-2.13 6.15-1.72.02 1.48-.04 2.96-.04 4.44-.99-.32-2.15-.23-3.02.37-.63.41-1.11 1.04-1.36 1.75-.21.51-.15 1.07-.14 1.61.24 1.64 1.82 3.02 3.5 2.87 1.12-.01 2.19-.66 2.77-1.61.19-.33.4-.67.41-1.06.1-1.79.06-3.57.07-5.36.01-4.03-.01-8.05.02-12.07z"/></svg>',
        'pinterest' => '<svg width="24" height="24" viewBox="0 0 24 24" fill="white" xmlns="http://www.w3.org/2000/svg"><path d="M12.017 0C5.396 0 .029 5.367.029 11.987c0 5.079 3.158 9.417 7.618 11.162-.105-.949-.199-2.403.041-3.439.219-.937 1.406-5.957 1.406-5.957s-.359-.72-.359-1.781c0-1.663.967-2.911 2.168-2.911 1.024 0 1.518.769 1.518 1.688 0 1.029-.653 2.567-.992 3.992-.285 1.193.6 2.165 1.775 2.165 2.128 0 3.768-2.245 3.768-5.487 0-2.861-2.063-4.869-5.008-4.869-3.41 0-5.409 2.562-5.409 5.199 0 1.033.394 2.143.889 2.741.099.12.112.225.085.345-.09.375-.293 1.199-.334 1.363-.053.225-.172.271-.401.165-1.495-.69-2.433-2.878-2.433-4.646 0-3.776 2.748-7.252 7.92-7.252 4.158 0 7.392 2.967 7.392 6.923 0 4.135-2.607 7.462-6.233 7.462-1.214 0-2.354-.629-2.758-1.379l-.749 2.848c-.269 1.045-1.004 2.352-1.498 3.146 1.123.345 2.306.535 3.55.535 6.607 0 11.985-5.365 11.985-11.987C23.97 5.39 18.592.026 11.985.026L12.017 0z"/></svg>',
        'telegram'  => '<svg width="24" height="24" viewBox="0 0 24 24" fill="white" xmlns="http://www.w3.org/2000/svg"><path d="M11.944 0A12 12 0 0 0 0 12a12 12 0 0 0 12 12 12 12 0 0 0 12-12A12 12 0 0 0 12 0a12 12 0 0 0-.056 0zm4.962 7.224c.1-.002.321.023.465.14a.506.506 0 0 1 .171.325c.016.093.036.306.02.472-.18 1.898-.962 6.502-1.36 8.627-.168.9-.499 1.201-.82 1.23-.696.065-1.225-.46-1.9-.902-1.056-.693-1.653-1.124-2.678-1.8-1.185-.78-.417-1.21.258-1.91.177-.184 3.247-2.977 3.307-3.23.007-.032.014-.15-.056-.212s-.174-.041-.249-.024c-.106.024-1.793 1.14-5.061 3.345-.48.33-.913.49-1.302.48-.428-.008-1.252-.241-1.865-.44-.752-.245-1.349-.374-1.297-.789.027-.216.325-.437.893-.663 3.498-1.524 5.83-2.529 6.998-3.014 3.332-1.386 4.025-1.627 4.476-1.635z"/></svg>',
        'whatsapp'  => '<svg width="24" height="24" viewBox="0 0 24 24" fill="white" xmlns="http://www.w3.org/2000/svg"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>',
        'discord'   => '<svg width="24" height="24" viewBox="0 0 24 24" fill="white" xmlns="http://www.w3.org/2000/svg"><path d="M20.317 4.3698a19.7913 19.7913 0 00-4.8851-1.5152.0741.0741 0 00-.0785.0371c-.211.3753-.4447.8648-.6083 1.2495-1.8447-.2762-3.68-.2762-5.4868 0-.1636-.3933-.4058-.8742-.6177-1.2495a.077.077 0 00-.0785-.037 19.7363 19.7363 0 00-4.8852 1.515.0699.0699 0 00-.0321.0277C.5334 9.0458-.319 13.5799.0992 18.0578a.0824.0824 0 00.0312.0561c2.0528 1.5076 4.0413 2.4228 5.9929 3.0294a.0777.0777 0 00.0842-.0276c.4616-.6304.8731-1.2952 1.226-1.9942a.076.076 0 00-.0416-.1057c-.6528-.2476-1.2743-.5495-1.8722-.8923a.077.077 0 01-.0076-.1277c.1258-.0943.2517-.1923.3718-.2914a.0743.0743 0 01.0776-.0105c3.9278 1.7933 8.18 1.7933 12.0614 0a.0739.0739 0 01.0785.0095c.1202.099.246.1981.3728.2924a.077.077 0 01-.0066.1276 12.2986 12.2986 0 01-1.873.8914.0766.0766 0 00-.0407.1067c.3604.698.7719 1.3628 1.225 1.9932a.076.076 0 00.0842.0286c1.961-.6067 3.9495-1.5219 6.0023-3.0294a.077.077 0 00.0313-.0552c.5004-5.177-.8382-9.6739-3.5485-13.6604a.061.061 0 00-.0312-.0286zM8.02 15.3312c-1.1825 0-2.1569-1.0857-2.1569-2.419 0-1.3332.9555-2.4189 2.157-2.4189 1.2108 0 2.1757 1.0952 2.1568 2.419 0 1.3332-.9555 2.4189-2.1569 2.4189zm7.9748 0c-1.1825 0-2.1569-1.0857-2.1569-2.419 0-1.3332.9554-2.4189 2.1569-2.4189 1.2108 0 2.1757 1.0952 2.1568 2.419 0 1.3332-.946 2.4189-2.1568 2.4189z"/></svg>',
        'link'      => '<svg width="24" height="24" viewBox="0 0 24 24" fill="white" xmlns="http://www.w3.org/2000/svg"><path d="M3.9 12c0-1.71 1.39-3.1 3.1-3.1h4V7H7c-2.76 0-5 2.24-5 5s2.24 5 5 5h4v-1.9H7c-1.71 0-3.1-1.39-3.1-3.1zM8 13h8v-2H8v2zm9-6h-4v1.9h4c1.71 0 3.1 1.39 3.1 3.1s-1.39 3.1-3.1 3.1h-4V17h4c2.76 0 5-2.24 5-5s-2.24-5-5-5z"/></svg>',
    ];

    /**
     * Service detection patterns (URL contains => service name)
     *
     * @var array
     */
    protected $servicePatterns = [
        'facebook.com'   => 'facebook',
        'twitter.com'    => 'twitter',
        'x.com'          => 'x',
        'linkedin.com'   => 'linkedin',
        'instagram.com'  => 'instagram',
        'github.com'     => 'github',
        'wordpress.org'  => 'wordpress',
        'wordpress.com'  => 'wordpress',
        'amazon.com'     => 'amazon',
        'youtube.com'    => 'youtube',
        'youtu.be'       => 'youtube',
        'tiktok.com'     => 'tiktok',
        'pinterest.com'  => 'pinterest',
        't.me'           => 'telegram',
        'telegram.me'    => 'telegram',
        'wa.me'          => 'whatsapp',
        'whatsapp.com'   => 'whatsapp',
        'discord.gg'     => 'discord',
        'discord.com'    => 'discord',
        'slack.com'      => 'slack',
        'dribbble.com'   => 'dribbble',
        'behance.net'    => 'behance',
        'medium.com'     => 'medium',
        'reddit.com'     => 'reddit',
        'twitch.tv'      => 'twitch',
        'spotify.com'    => 'spotify',
    ];

    /**
     * Render the social links block
     *
     * @return string Email-compatible HTML
     */
    public function render(): string
    {
        $socialLinks = $this->extractSocialLinks();

        if (empty($socialLinks)) {
            return '';
        }

        // Build container styles
        $containerStyles = $this->buildContainerStyles();

        // Render social links
        $linksHtml = '';
        foreach ($socialLinks as $link) {
            $linksHtml .= $this->renderSocialLink($link);
        }

        $html = "<div style=\"{$containerStyles}\">{$linksHtml}</div>";

        return $this->wrapInTable($html, 'fluent-social-links');
    }

    /**
     * Extract social links from innerBlocks or innerHTML
     *
     * @return array Array of social link data
     */
    protected function extractSocialLinks(): array
    {
        $links = [];

        // First try innerBlocks (preferred)
        if (!empty($this->innerBlocks)) {
            $links = $this->extractFromInnerBlocks();
        }

        // Fallback to innerHTML parsing
        if (empty($links) && !empty($this->innerHTML)) {
            $links = $this->extractFromInnerHtml();
        }

        return $links;
    }

    /**
     * Extract social links from innerBlocks
     *
     * @return array
     */
    protected function extractFromInnerBlocks(): array
    {
        $links = [];

        foreach ($this->innerBlocks as $block) {
            if ($block['blockName'] !== 'core/social-link') {
                continue;
            }

            $blockAttrs = $block['attrs'] ?? [];
            $blockInner = $block['innerHTML'] ?? '';

            // Reconstruct innerHTML from innerContent if needed
            if (empty($blockInner) && !empty($block['innerContent'])) {
                $blockInner = implode('', array_filter($block['innerContent'], 'is_string'));
            }

            $url = $blockAttrs['url'] ?? '';
            $service = $blockAttrs['service'] ?? 'link';
            $label = $blockAttrs['label'] ?? '';

            // Extract URL from innerHTML if not in attrs
            if (empty($url) && !empty($blockInner)) {
                if (preg_match('/<a[^>]*href=["\']([^"\']*)["\']/', $blockInner, $urlMatch)) {
                    $url = $urlMatch[1];
                }
            }

            // Extract label from aria-label
            if (empty($label) && !empty($blockInner)) {
                if (preg_match('/aria-label=["\']([^"\']*)["\']/', $blockInner, $labelMatch)) {
                    $label = $labelMatch[1];
                }
            }

            // Auto-detect service from URL if generic
            if ($service === 'link' && !empty($url)) {
                $service = $this->detectServiceFromUrl($url);
            }

            if (!empty($url)) {
                $links[] = [
                    'url'     => $url,
                    'service' => $service,
                    'label'   => $label,
                ];
            }
        }

        return $links;
    }

    /**
     * Extract social links from innerHTML (fallback)
     *
     * @return array
     */
    protected function extractFromInnerHtml(): array
    {
        $links = [];

        preg_match_all(
            '/<li[^>]*class="[^"]*wp-social-link[^"]*"[^>]*>.*?<a[^>]*href=["\']([^"\']*)["\'][^>]*(?:aria-label=["\']([^"\']*)["\'])?[^>]*>.*?<\/a>.*?<\/li>/s',
            $this->innerHTML,
            $matches
        );

        if (empty($matches[1])) {
            return $links;
        }

        foreach ($matches[0] as $index => $match) {
            $url = $matches[1][$index];
            $label = $matches[2][$index] ?? '';

            // Detect service from class or URL
            $service = $this->detectServiceFromMatch($match, $url);

            $links[] = [
                'url'     => $url,
                'service' => $service,
                'label'   => $label,
            ];
        }

        return $links;
    }

    /**
     * Detect social service from URL
     *
     * @param string $url
     * @return string Service name
     */
    protected function detectServiceFromUrl(string $url): string
    {
        foreach ($this->servicePatterns as $pattern => $service) {
            if (strpos($url, $pattern) !== false) {
                return $service;
            }
        }

        return 'link';
    }

    /**
     * Detect service from HTML match and URL
     *
     * @param string $match HTML match
     * @param string $url URL
     * @return string Service name
     */
    protected function detectServiceFromMatch(string $match, string $url): string
    {
        // Check class names first
        foreach ($this->servicePatterns as $pattern => $service) {
            $classPattern = "wp-social-link-{$service}";
            if (strpos($match, $classPattern) !== false) {
                return $service;
            }
        }

        // Fallback to URL detection
        return $this->detectServiceFromUrl($url);
    }

    /**
     * Size presets: maps Gutenberg size attr to icon dimensions
     *
     * @var array
     */
    protected $sizePresets = [
        'small'  => ['outer' => 36, 'inner' => 20, 'padding' => 8],
        'normal' => ['outer' => 44, 'inner' => 24, 'padding' => 10],
        'large'  => ['outer' => 56, 'inner' => 32, 'padding' => 12],
        'huge'   => ['outer' => 72, 'inner' => 42, 'padding' => 15],
    ];

    /**
     * Build container styles
     *
     * @return string CSS styles
     */
    protected function buildContainerStyles(): string
    {
        // Resolve alignment from layout.justifyContent
        $justify = $this->attrs['layout']['justifyContent'] ?? 'center';
        $alignMap = [
            'left'       => 'left',
            'flex-start' => 'left',
            'center'     => 'center',
            'right'      => 'right',
            'flex-end'   => 'right',
        ];
        $textAlign = $alignMap[$justify] ?? 'center';

        $styles = "margin: 20px 0; text-align: {$textAlign};";

        // Add spacing styles
        $styles .= $this->getSpacingStyles('margin');
        $styles .= $this->getSpacingStyles('padding');

        return $styles;
    }

    /**
     * Get icon dimensions based on block size attribute
     *
     * @return array ['outer' => int, 'inner' => int, 'padding' => int]
     */
    protected function getIconDimensions(): array
    {
        $size = $this->attrs['size'] ?? 'normal';
        return $this->sizePresets[$size] ?? $this->sizePresets['normal'];
    }

    /**
     * Get icon background color, checking custom colors then brand fallback
     *
     * @param string $service The social service name
     * @return string Hex color
     */
    protected function getIconBackgroundColor(string $service): string
    {
        // 1. customIconBackgroundColor (direct hex)
        if (!empty($this->attrs['customIconBackgroundColor'])) {
            return $this->attrs['customIconBackgroundColor'];
        }

        // 2. iconBackgroundColor slug
        if (!empty($this->attrs['iconBackgroundColor'])) {
            return $this->getColorFromSlug($this->attrs['iconBackgroundColor']);
        }

        // 3. Brand color fallback
        return $this->brandColors[$service] ?? $this->brandColors['link'];
    }

    /**
     * Get icon color (SVG fill color)
     *
     * @return string Hex color
     */
    protected function getIconColor(): string
    {
        // 1. customIconColor (direct hex)
        if (!empty($this->attrs['customIconColor'])) {
            return $this->attrs['customIconColor'];
        }

        // 2. iconColor slug
        if (!empty($this->attrs['iconColor'])) {
            return $this->getColorFromSlug($this->attrs['iconColor']);
        }

        // 3. Default white
        return '#ffffff';
    }

    /**
     * Render a single social link
     *
     * @param array $link Social link data
     * @return string HTML
     */
    protected function renderSocialLink(array $link): string
    {
        $url = htmlspecialchars($link['url']);
        $service = $link['service'];
        $label = $link['label'] ?: ucfirst($service);

        // Resolve colors (custom overrides brand)
        $bgColor = $this->getIconBackgroundColor($service);
        $iconColor = $this->getIconColor();

        // Get size dimensions
        $dims = $this->getIconDimensions();

        $icon = $this->icons[$service] ?? $this->icons['link'];

        // Update SVG fill color and dimensions when custom icon color is set
        $icon = preg_replace('/fill="[^"]*"/', 'fill="' . $iconColor . '"', $icon);
        $icon = preg_replace('/width="\d+"/', 'width="' . $dims['inner'] . '"', $icon);
        $icon = preg_replace('/height="\d+"/', 'height="' . $dims['inner'] . '"', $icon);

        $iconStyles = "display: inline-block; width: {$dims['outer']}px; height: {$dims['outer']}px; background-color: {$bgColor}; border-radius: 50%; text-align: center; padding: {$dims['padding']}px; box-sizing: border-box;";

        return '<a href="' . $url . '" style="display: inline-block; margin: 0 5px; text-decoration: none;" title="' . htmlspecialchars($label) . '">'
            . '<span style="' . $iconStyles . '">' . $icon . '</span>'
            . '</a>';
    }
}