import {ElMessage, ElMessageBox, ElNotification} from 'element-plus';

export function useElementPlusComponents(app) {

    app.config.globalProperties.$notify = ElNotification;
    app.config.globalProperties.$confirm = ElMessageBox.confirm;
    app.config.globalProperties.$message = ElMessage;
}
