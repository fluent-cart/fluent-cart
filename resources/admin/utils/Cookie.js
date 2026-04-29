export class Cookie
{
	get(name, str = '') {
		const parsed = this.parse(str);

		if (parsed.hasOwnProperty(name)) {
			return JSON.parse(atob(parsed[name]));
		}
	}

	parse(str = '') {
        return (str || document.cookie)
        .split(';')
        .map(v => v.split('='))
        .reduce((acc, v) => {
          acc[decodeURIComponent(v[0].trim())] = decodeURIComponent(v[1].trim());
          return acc;
        }, {});
    }
}

const cookie = new Cookie;

export default cookie;