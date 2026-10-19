<?php
declare(strict_types=1);
// Deterministic FN-08 lifecycle policies supplement enabled DB recommendation conditions.
// They do not alter FN-07 thresholds, workflow decisions or business data.
return [
    'version'=>'fn08-workflow-v1',
    'initial'=>['category'=>'Xác minh ban đầu','text'=>'Đối chiếu chỉ số {meter}, kiểm tra tình trạng công tơ và điều kiện sử dụng {utility} của căn {apartment} kỳ {period}. Chưa kết luận lỗi công tơ khi chưa có bằng chứng.'],
    'pending_recheck'=>['category'=>'Theo dõi kiểm tra kỹ thuật','text'=>'Theo dõi yêu cầu kiểm tra đã giao cho Kỹ thuật và chờ bằng chứng đối với {meter}, căn {apartment}, kỳ {period}. Không tạo thêm yêu cầu kiểm tra trùng.'],
    'management_pending'=>['category'=>'Chờ quyết định Ban quản lý','text'=>'Ban quản lý xem xét kết quả kiểm tra {meter} kỳ {period}, đối chiếu bằng chứng và quyết định xác nhận hoặc điều chỉnh chỉ số. Kết quả kỹ thuật không thay thế quyết định của Ban quản lý.'],
    'verified_confirm'=>['category'=>'Theo dõi sau xác minh','text'=>'Dữ liệu đã được Ban quản lý xác nhận sau kiểm tra. Xem xét thông báo cư dân căn {apartment}, tìm hiểu mức sử dụng {utility} tăng, hướng dẫn tiết kiệm và theo dõi kỳ tiếp theo. Không yêu cầu kiểm tra lại công tơ khi chưa có lý do mới.'],
    'verified_other'=>['category'=>'Rà soát kết luận','text'=>'Ban quản lý đã xác nhận dữ liệu nhưng bằng chứng chưa xác nhận bất thường là có thật. Rà soát kết luận và nguyên nhân của cảnh báo trước khi đưa ra biện pháp xử lý. Không suy đoán công tơ bị lỗi.'],
    'reading_adjusted'=>['category'=>'Đánh giá lại sau điều chỉnh','text'=>'Ban quản lý đã điều chỉnh chỉ số {meter}. Đối chiếu, xác nhận lại dữ liệu tiêu thụ đã sửa và đánh giá lại cảnh báo kỳ {period}; không mặc định bất thường cũ còn đúng. Giữ nguyên phiếu đã phát hành và lịch sử tính phí; xử lý chênh lệch theo quy trình được phép.'],
];
