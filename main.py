import os
import glob
import logging
import colorlog
import cv2
import numpy as np
from PIL import Image
from flask import Flask, request, jsonify
import keras_ocr

import base64



app = Flask(__name__)

# Настройка логирования с использованием colorlog
handler = colorlog.StreamHandler()
handler.setFormatter(colorlog.ColoredFormatter(
    "%(log_color)s%(asctime)s - %(levelname)s - %(message)s",
    datefmt=None,
    reset=True,
    log_colors={
        'DEBUG': 'cyan',
        'INFO': 'green',
        'WARNING': 'yellow',
        'ERROR': 'red',
        'CRITICAL': 'bold_red',
    }
))

logger = colorlog.getLogger()
logger.addHandler(handler)
logger.setLevel(logging.INFO)

input_dir = 'input'
output_dir = 'original'
binary_dir = 'binary'
annotated_dir = 'annotated'
mask_dir = 'result'
angle = 90
lower_yellow = [20, 100, 100]
upper_yellow = [30, 255, 255]

os.makedirs(input_dir, exist_ok=True)
os.makedirs(output_dir, exist_ok=True)
os.makedirs(binary_dir, exist_ok=True)
os.makedirs(annotated_dir, exist_ok=True)
os.makedirs(mask_dir, exist_ok=True)

pipeline = keras_ocr.pipeline.Pipeline()

def encode_image_to_base64(image_path):
    with open(image_path, "rb") as image_file:
        return base64.b64encode(image_file.read()).decode('utf-8')


def load_image(image_path):
    logger.info(f'Загрузка изображения: {image_path}')
    return cv2.imread(image_path)

def save_image(image, image_path):
    logger.info(f'Сохранение изображения: {image_path}')
    cv2.imwrite(image_path, image)

def convert_to_grayscale(image):
    logger.info('Преобразование изображения в оттенки серого')
    return cv2.cvtColor(image, cv2.COLOR_BGR2GRAY)

def enhance_contrast(image):
    logger.info('Увеличение контраста изображения')
    clahe = cv2.createCLAHE(clipLimit=9.0, tileGridSize=(8, 8))
    return clahe.apply(image)

def apply_median_blur(image):
    logger.info('Применение медианного размытия')
    return cv2.medianBlur(image, 5)

def apply_gaussian_blur(image):
    logger.info('Применение гауссовского размытия')
    return cv2.GaussianBlur(image, (5, 5), 0)

def apply_threshold_otsu(image):
    logger.info('Применение порогового значения методом Оцу')
    _, binary_image = cv2.threshold(image, 0, 255, cv2.THRESH_BINARY + cv2.THRESH_OTSU)
    return binary_image

def apply_morphological_operations(image):
    logger.info('Применение морфологических операций')
    kernel = cv2.getStructuringElement(cv2.MORPH_RECT, (3, 3))
    closed_image = cv2.morphologyEx(image, cv2.MORPH_CLOSE, kernel)
    return cv2.morphologyEx(closed_image, cv2.MORPH_OPEN, kernel)

def remove_small_objects(image, min_size=50):
    logger.info(f'Удаление маленьких объектов размером менее {min_size} пикселей')
    num_labels, labels, stats, centroids = cv2.connectedComponentsWithStats(image, connectivity=8)
    sizes = stats[1:, -1]
    cleaned_image = np.zeros((labels.shape), np.uint8)
    for i in range(0, num_labels - 1):
        if sizes[i] >= min_size:
            cleaned_image[labels == i + 1] = 255
    return cleaned_image

def rotate_image(image, angle):
    logger.info(f'Поворот изображения на {angle} градусов')
    pil_image = Image.fromarray(image)
    rotated_pil_image = pil_image.rotate(angle, resample=Image.BICUBIC, expand=True)
    return np.array(rotated_pil_image)

def resize_to_original(image, original_size):
    logger.info(f'Изменение размера изображения до исходного: width={original_size[1]}, height={original_size[0]}')
    return cv2.resize(image, (original_size[1], original_size[0]))

def draw_text_boxes(image, predictions, expansion_pixels=150):
    overlay = image.copy()
    for _, box in predictions:
        points = np.array(box, np.int32)
        min_x = np.min(points[:, 0]) - expansion_pixels
        max_x = np.max(points[:, 0]) + expansion_pixels
        min_y = np.min(points[:, 1])
        max_y = np.max(points[:, 1])
        expanded_points = np.array([
            [min_x, min_y],
            [max_x, min_y],
            [max_x, max_y],
            [min_x, max_y]
        ], np.int32)
        cv2.fillPoly(overlay, [expanded_points], color=(0, 255, 255))
    alpha = 0.5
    cv2.addWeighted(overlay, alpha, image, 1 - alpha, 0, image)

def process_image_with_rotation(input_image_path, output_image_path, rotation_angle):
    logger.info('Запуск обработки изображения с поворотом')
    input_image = load_image(input_image_path)
    original_size = input_image.shape[:2]
    logger.info(f'Исходные размеры изображения: width={original_size[1]}, height={original_size[0]}')
    current_image = input_image
    gray_image = convert_to_grayscale(input_image)
    enhanced_image = enhance_contrast(gray_image)
    blurred_image = apply_median_blur(enhanced_image)
    binary_image = apply_threshold_otsu(blurred_image)
    binary_output_path = os.path.join(binary_dir, 'binary_image.png')
    save_image(binary_image, binary_output_path)

    for angle in range(0, 360, rotation_angle):
        rotated_image = rotate_image(current_image, angle)
        rotated_output_path = f'{output_dir}/rotated_{angle}_degrees.png'
        save_image(rotated_image, rotated_output_path)
        prediction_groups = pipeline.recognize([rotated_image])
        annotated_image = rotated_image.copy()
        draw_text_boxes(annotated_image, prediction_groups[0])
        save_image(annotated_image, f'{annotated_dir}/annotated_rotated_{angle}_degrees.png')
        current_image = annotated_image

    resized_image = resize_to_original(current_image, original_size)
    save_image(resized_image, output_image_path)
    logger.info('Завершение обработки изображения с поворотом')

def rotate_image_180(image_path):
    image = cv2.imread(image_path)
    if image is None:
        raise ValueError(f"Не удалось загрузить изображение: {image_path}")
    return cv2.rotate(image, cv2.ROTATE_180)

def create_white_mask(image_path, lower_yellow, upper_yellow):
    image = cv2.imread(image_path)
    if image is None:
        raise ValueError(f"Изображение по пути {image_path} не найдено")
    hsv = cv2.cvtColor(image, cv2.COLOR_BGR2HSV)
    lower_yellow = np.array(lower_yellow)
    upper_yellow = np.array(upper_yellow)
    mask = cv2.inRange(hsv, lower_yellow, upper_yellow)
    result_image = image.copy()
    result_image[mask != 0] = [255, 255, 255]
    return mask, result_image

def process_and_save_image(image_path, lower_yellow, upper_yellow, output_dir='mask'):
    logger.info('Запуск обработки изображения с маской')
    os.makedirs(output_dir, exist_ok=True)
    image = load_image(image_path)
    if image is None:
        raise ValueError(f"Изображение по пути {image_path} не найдено")
    mask, result_image = create_white_mask(image_path, lower_yellow, upper_yellow)
    base_filename = os.path.basename(image_path)
    mask_path = os.path.join(output_dir, base_filename)
    save_image(mask, mask_path)
    logger.info('Завершение обработки изображения с маской')

@app.route('/upload_automatic', methods=['POST'])
def upload_image():
    if 'image' not in request.files:
        return jsonify({'error': 'No image part in the request'}), 400
    file = request.files['image']
    if file.filename == '':
        return jsonify({'error': 'No selected file'}), 400
    file_path = os.path.join(input_dir, file.filename)
    file.save(file_path)
    return jsonify({'message': 'Image uploaded successfully', 'file_path': file_path}), 200

@app.route('/process_automatic', methods=['POST'])
def process_images():
    input_image_paths = glob.glob(os.path.join(input_dir, '*.png'))
    if not input_image_paths:
        return jsonify({'error': 'No images to process'}), 400

    processed_images = []

    for input_image_path in input_image_paths:
        base_filename = os.path.basename(input_image_path)
        output_image_path = os.path.join(output_dir, base_filename)
        
        # Запуск обработки изображения с поворотом
        process_image_with_rotation(input_image_path, output_image_path, angle)
        
        # Поворот изображения на 180 градусов и обработка с маской
        rotated_image = rotate_image_180(output_image_path)
        rotated_image_path = os.path.join(output_dir, base_filename)
        cv2.imwrite(rotated_image_path, rotated_image)
        process_and_save_image(rotated_image_path, lower_yellow, upper_yellow, mask_dir)
        
        # Удаление файла из папки original, если он там оказался
        if os.path.exists(output_image_path):
            os.remove(output_image_path)

        # Encode processed images to base64 and store in list
        processed_images.append({
            'filename': base_filename,
            'rotated_image': encode_image_to_base64(rotated_image_path),
            'mask_image': encode_image_to_base64(os.path.join(mask_dir, base_filename))
        })

    return jsonify({'message': 'Image processing completed', 'processed_images': processed_images}), 200


if __name__ == "__main__":
    app.run(debug=True)
