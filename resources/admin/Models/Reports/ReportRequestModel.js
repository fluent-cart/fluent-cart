import Model from "@/utils/model/Model";
import Rest from "@/utils/http/Rest";
import Arr from "@/utils/support/Arr";

// The report models' shared request: GET reports/{endPoint}, keep the answer at data[key] (a dotted key works),
// and flag isBusy[key] while it runs
export default class ReportRequestModel extends Model {
    makeRequest(endPoint, query, accessor, key) {
        if (key) {
            Arr.set(this.data.isBusy, key, true);
        }

        const request = Rest.get(`reports/${endPoint}`, typeof query === "object" ? query : {});

        request
            .then((response) => {
                if (key != null && accessor != null) {
                    Arr.set(this.data, key, Arr.get(response, accessor));
                } else {
                    Object.assign(this.data, response);
                }
            })
            .catch(() => {
            })
            .finally(() => {
                if (key) {
                    Arr.set(this.data.isBusy, key, false);
                }
            });

        return request;
    }
}
