import Model from "@/utils/model/Model";
import Rest from "@/utils/http/Rest";
import Notify from "@/utils/Notify";


class StorageDriverModel extends Model {
    data = {
        drivers: {},
        selectedDriver: '',
        isDriversLoaded: false,
        loadingDriver: false,
        buckets: {},
        bucketLoadingStates: {},
        loadingFiles: false,
        onFileUploadedCallbacks: {}
    }


    get drivers() {
        return Object.values(this.data.drivers);
    }

    getActiveDrivers = () => {
        this.data.drivers = []
        Rest.get('settings/storage-drivers/active-drivers')
            .then((data) => {
                this.data.drivers = data.drivers;
                if (this.data.drivers.length > 0) {
                    this.data.selectedDriver.value = this.data.drivers[0].route;
                }

                Object.values(this.data.drivers).forEach((driver) => {
                    if (driver.has_bucket) {
                        this.data.buckets[driver.route] = driver.buckets;
                        this.data.bucketLoadingStates[driver.route] = true;
                    }
                })

            }).catch((errors) => {
            console.log(errors, 'errors');
        })
            .finally(() => {
                this.data.isDriversLoaded = true;
            })
        ;
    }

    hasBucket(driver) {
        return this.data.buckets.hasOwnProperty(driver);
    }

    getBuckets = (driver) => {
        if (this.data.bucketLoadingStates[driver] === false) {
            this.loadBucketList(driver);
            return [];
        }

        if (this.data.buckets[driver]) {
            return this.data.buckets[driver];
        }
        return [];
    }

    isBucketLoading(driver){
        return this.data.bucketLoadingStates[driver] === 'loading';
    }

    isBucketLoaded(driver){
        return this.data.bucketLoadingStates[driver] === true;
    }

    loadBucketList = (driver) => {

        if (this.isBucketLoaded(driver) || this.isBucketLoading(driver)) {
            return;
        }

        this.data.bucketLoadingStates[driver] = 'loading';

        Rest.get('files/bucket-list', {
            driver: driver
        }).then((data) => {
            this.data.bucketLoadingStates[driver] = true;
            this.data.buckets[driver] = data.buckets;
        })
            .catch((errors) => {
                this.data.bucketLoadingStates[driver] = false;
            })
            .finally(() => {

            });
    }

    getFileList = async (driver, searchQuery = '', bucket) => {

        searchQuery = searchQuery.trim();

        if (this.hasBucket(driver) && !bucket) {
            return [];
        }

        let files = [];

        this.data.loadingFiles = true;
        await Rest.get('files', {
            driver: driver,
            activeBucket: bucket,
            search: searchQuery
        }).then((data) => {
            files = data.files;
        })
            .catch((errors) => {
                Notify.error(errors);
            })
            .finally(() => {
                this.data.loadingFiles = false;
            });
        return files;
    }

    onFileUploaded = (callback, driver) => {
        if(typeof callback === 'function' && !this.data.onFileUploadedCallbacks.hasOwnProperty(driver)){
            this.data.onFileUploadedCallbacks[driver] = callback;
        }
    }

    fileUploaded = (response) => {
        const driver = response.file.driver;
        if(!driver || !this.data.onFileUploadedCallbacks.hasOwnProperty(driver)){
            return;
        }

        if(typeof this.data.onFileUploadedCallbacks[driver] === 'function'){
            this.data.onFileUploadedCallbacks[driver](response);
        }

    }


}

export function useStorageDriverModel() {
    return StorageDriverModel.init();
}
